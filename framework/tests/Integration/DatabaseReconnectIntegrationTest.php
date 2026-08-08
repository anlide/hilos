<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use ErrorException;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\BaseManager;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Exception\SqlRuntime\TableNotFoundException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use mysqli;

/**
 * Integration coverage for what mysqli's own exception mode makes visible: reconnect after a
 * lost connection, a failing statement inside a multi-query, and a refused connect.
 *
 * Kept apart from {@see DatabaseWorkflowIntegrationTest} because every test here destroys its
 * connection on purpose, and that must not travel down a #[Depends] chain of unrelated tests.
 */
final class DatabaseReconnectIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Table name nothing creates, so a statement naming it always fails with 1146. */
    private const string MISSING_TABLE = 'hilos_fw_test_no_such_table';

    /** Port nothing listens on, used to provoke a refused connect. */
    private const int CLOSED_PORT = 1;

    /**
     * A connection killed mid-life is reopened by the next query instead of surfacing as an error.
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     * @throws EnvException When DB env variables are missing or invalid.
     */
    public function testQueryReconnectsAfterConnectionIsKilled(): void
    {
        $killedId = $this->currentConnectionId();
        $this->killConnection($killedId);

        Database::sql('SELECT 1 AS v');
        $row = Database::row();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['v']);

        $this->assertNotSame($killedId, $this->currentConnectionId(), 'Query must run on a reopened connection');
    }

    /**
     * A statement that fails behind the first one of a multi-query is reported, not swallowed.
     *
     * The first statement succeeds, so sql() returns normally; the failure lives in the second
     * result set and only collectAll() reaches it.
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    public function testFailingSecondStatementSurfacesOnCollectAll(): void
    {
        $collection = Database::sql('SELECT 1; SELECT * FROM `' . self::MISSING_TABLE . '`');

        $this->expectException(TableNotFoundException::class);
        $collection->collectAll();
    }

    /**
     * ping() answers false on a killed connection and keeps its bool contract.
     *
     * Runs under the same warning-to-exception handler the daemon installs
     * ({@see BaseManager::errorHandler()}), because mysqli reports a dead socket as a PHP
     * warning first and its own exception second: the handler is what decides which of the
     * two reaches the caller, and only mysqli_sql_exception may.
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     * @throws EnvException When DB env variables are missing or invalid.
     */
    public function testPingReturnsFalseOnKilledConnection(): void
    {
        $this->killConnection($this->currentConnectionId());

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $this->assertFalse(Database::ping());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A refused connect arrives as the typed connection exception, retries included.
     *
     * Points the primary index at the dead port instead of adding one of its own: Database
     * has no way to forget a configured index, and setUp() re-points the primary before every
     * test anyway, so nothing of this survives the test.
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     * @throws EnvException When DB env variables are missing or invalid.
     */
    public function testConnectToClosedPortThrowsTypedException(): void
    {
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(
            index: DatabaseConnectionDefaults::PRIMARY_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST],
            user: Hilos::$env[EnvConstants::DB_USERNAME],
            password: Hilos::$env[EnvConstants::DB_PASSWORD],
            database: Hilos::$env[EnvConstants::DB_DATABASE],
            port: self::CLOSED_PORT,
            charset: DatabaseConnectionDefaults::CHARSET,
        );

        $this->expectException(CantConnectToMysqlServerException::class);
        Database::connect(
            DatabaseConnectionDefaults::PRIMARY_INDEX,
            retryOnConnectionError: true,
            maxRetries: 2,
            retryDelaySeconds: 0,
        );
    }

    /**
     * @return int Thread id of the connection Database is currently using
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    private function currentConnectionId(): int
    {
        Database::sql('SELECT CONNECTION_ID() AS id');
        $row = Database::row();
        $this->assertNotNull($row);

        return (int) $row['id'];
    }

    /**
     * Kills a server thread from a connection of its own, so the layer under test sees the
     * loss exactly as it would see a server-side timeout.
     *
     * @param int $connectionId Thread id to kill
     * @throws EnvException When DB env variables are missing or invalid.
     */
    private function killConnection(int $connectionId): void
    {
        $killer = new mysqli(
            Hilos::$env[EnvConstants::DB_HOST],
            Hilos::$env[EnvConstants::DB_USERNAME],
            Hilos::$env[EnvConstants::DB_PASSWORD],
            Hilos::$env[EnvConstants::DB_DATABASE],
            Hilos::$env->int(EnvConstants::DB_PORT),
        );
        $killer->query("KILL {$connectionId}");
        $killer->close();
    }
}

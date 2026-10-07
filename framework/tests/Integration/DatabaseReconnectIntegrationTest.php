<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use ErrorException;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\BaseManager;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Exception\SqlRuntime\TableNotFoundException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Logger;
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

    private const string RESEND_TABLE = 'hilos_fw_test_resend';

    private const string RESEND_VALUE = 'private-probe-value';

    private string $logFile = '';

    private bool $resendTableCreated = false;

    /**
     * @throws DatabaseException When cleanup SQL fails
     */
    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if ($this->logFile !== '') {
            unlink($this->logFile);
        }
        if ($this->resendTableCreated && Database::isConnected()) {
            Database::sql('DROP TABLE IF EXISTS `' . self::RESEND_TABLE . '`');
        }
        parent::tearDown();
    }

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
     * The first statement replayed on a replacement primary link sees its active receipt.
     * The next operation starts without that number, including after another reconnect.
     *
     * @throws DatabaseException When a query or reconnect fails
     * @throws EnvException When DB env variables are missing
     */
    public function testReconnectedPrimaryRestoresAndClearsReceipt(): void
    {
        Database::setJournalReceiptId(737);
        try {
            $this->killConnection($this->currentConnectionId());
            Database::sql('SELECT @hilos_receipt AS receipt');
            $this->assertSame(737, (int) Database::field('receipt'));
        } finally {
            Database::setJournalReceiptId(null);
        }

        Database::sql('SELECT @hilos_receipt AS receipt');
        $this->assertNull(Database::field('receipt'));

        $this->killConnection($this->currentConnectionId());
        Database::sql('SELECT @hilos_receipt AS receipt');
        $this->assertNull(Database::field('receipt'));
    }

    /**
     * @throws DatabaseException When the connection or query fails
     * @throws EnvException When DB env variables are unavailable
     */
    public function testReconnectedSessionKeepsTheCollation(): void
    {
        $this->killConnection($this->currentConnectionId());

        Database::sql('SELECT @@collation_connection AS collation');
        $this->assertSame(DatabaseConnectionDefaults::COLLATION, Database::field('collation'));
    }

    /**
     * @throws DatabaseException When the connection or query fails unexpectedly
     * @throws EnvException When DB env variables are unavailable
     */
    public function testEmptySlotIsOpenedByTheNextStatement(): void
    {
        Database::close();
        Database::configure(
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            port: self::CLOSED_PORT,
        );

        try {
            Database::sql('SELECT 1');
            self::fail('The closed port must refuse the first statement');
        } catch (CantConnectToMysqlServerException) {
            // The next statement must be able to open the same slot after configuration changes.
        }

        Database::configure(
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            port: Hilos::$env[EnvConstants::DB_PORT]->int(),
        );
        Database::sql('SELECT 1 AS value');
        $this->assertSame(1, (int) Database::field('value'));
    }

    /**
     * @throws DatabaseException When the connection or query fails unexpectedly
     */
    public function testConnectionClosedByHandStaysClosed(): void
    {
        Database::close();

        $this->expectException(DatabaseConnectionException::class);
        $this->expectExceptionMessage('Not connected to database at index 0');
        Database::sql('SELECT 1');
    }

    /**
     * @throws DatabaseException When the connection or query fails
     * @throws EnvException When DB env variables are unavailable
     */
    public function testResentWriteIsLoggedOnce(): void
    {
        Database::sql('CREATE TABLE `' . self::RESEND_TABLE . '` (`value` VARCHAR(100))');
        $this->resendTableCreated = true;
        $this->captureLog();
        $this->killConnection($this->currentConnectionId());

        $query = 'INSERT INTO `' . self::RESEND_TABLE . '` (`value`) VALUES (?)';
        Database::sql($query, [self::RESEND_VALUE]);
        Database::sql('SELECT `value` FROM `' . self::RESEND_TABLE . '`');
        $this->assertSame(self::RESEND_VALUE, Database::field('value'));
        Database::sql('SELECT COUNT(*) AS count FROM `' . self::RESEND_TABLE . '`');
        $this->assertSame(1, (int) Database::field('count'));

        $log = file_get_contents($this->logFile);
        $this->assertNotFalse($log);
        $this->assertSame(1, substr_count($log, 'A write was sent again after its database connection was lost'));
        $this->assertStringContainsString($query, $log);
        $this->assertStringNotContainsString(self::RESEND_VALUE, $log);
    }

    /**
     * @throws DatabaseException When the connection or query fails
     * @throws EnvException When DB env variables are unavailable
     */
    public function testResentReadLogsNothing(): void
    {
        $this->captureLog();
        $this->killConnection($this->currentConnectionId());

        Database::sql('SELECT 1');
        $log = file_get_contents($this->logFile);
        $this->assertNotFalse($log);
        $this->assertStringNotContainsString('A write was sent again after its database connection was lost', $log);
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
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
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
            Hilos::$env[EnvConstants::DB_HOST]->string(),
            Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            Hilos::$env[EnvConstants::DB_PORT]->int(),
        );
        $killer->query("KILL {$connectionId}");
        $killer->close();
    }

    private function captureLog(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'hilos-database-reconnect-log');
        Logger::setLogFile($this->logFile);
    }
}

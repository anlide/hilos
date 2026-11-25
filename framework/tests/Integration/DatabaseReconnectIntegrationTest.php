<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use ErrorException;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\BaseManager;
use Hilos\Database\ConnectionReadBoundary;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Exception\SqlRuntime\GoneAwayException;
use Hilos\Database\Exception\SqlRuntime\LostConnectionException;
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

    /** The first physical loss holds for later reads in the same delivery and releases on the next delivery. */
    public function testReadBoundaryParksTheLostLinkUntilTheNextDelivery(): void
    {
        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;
        $killedId = $this->currentConnectionId();
        $this->killConnection($killedId);
        $boundary = new ConnectionReadBoundary();

        Database::withSqlReadBoundary($index, $boundary, static function () use ($boundary, $index): void {
            try {
                Database::sql('SELECT 1');
                self::fail('The first read should report the lost link');
            } catch (GoneAwayException | LostConnectionException $failure) {
                self::assertSame($failure, $boundary->failure());
            }
            self::assertFalse(Database::isConnected($index));
            try {
                Database::sql('SELECT 1');
                self::fail('The second read should leave the link closed');
            } catch (GoneAwayException | LostConnectionException $failure) {
                self::assertSame($boundary->failure(), $failure);
            }
            self::assertFalse(Database::isConnected($index));
        });

        $fresh = new ConnectionReadBoundary();
        $value = Database::withSqlReadBoundary($index, $fresh, static fn (): int => (int) Database::sql('SELECT 42 AS v')->field('v'));
        self::assertSame(42, $value);
        self::assertNull($fresh->failure());
        self::assertNotSame($killedId, $this->currentConnectionId());
    }

    /** The first loss is discovered by the physical rollback after a local refusal. */
    public function testReadBoundaryHoldsTheConnectionLossFromSilentSnapshotRollback(): void
    {
        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;
        $boundary = new ConnectionReadBoundary();
        $refusal = new DbCollectionNotReadableException('The source is not wired');
        $killedId = $this->currentConnectionId();
        $returns = Database::returnsAfterLoss();
        $this->captureLog();

        Database::withSqlReadBoundary($index, $boundary, function () use ($refusal, $killedId, $boundary, $index, $returns): void {
            try {
                Database::readSnapshot(DatabaseNames::PRIMARY, function () use ($refusal, $killedId): never {
                    self::assertSame($killedId, $this->currentConnectionId());
                    $this->killConnection($killedId);
                    throw $refusal;
                });
                self::fail('The snapshot must raise the local refusal');
            } catch (DbCollectionNotReadableException $failure) {
                self::assertSame($refusal, $failure);
            }

            $log = (string) file_get_contents($this->logFile);
            self::assertStringContainsString('A silent transaction rollback failed:', $log);
            self::assertStringContainsString('Query: ROLLBACK', $log);
            self::assertFalse(Database::isConnected($index));
            $loss = $boundary->failure();
            self::assertTrue($loss instanceof GoneAwayException || $loss instanceof LostConnectionException);
            self::assertSame('ROLLBACK', $loss->getQuery());
            try {
                Database::sql('SELECT 1');
                self::fail('The next read must leave the link closed');
            } catch (GoneAwayException | LostConnectionException $failure) {
                self::assertSame($loss, $failure);
            }
            self::assertFalse(Database::isConnected($index));
            self::assertSame($returns, Database::returnsAfterLoss());
        });

        $fresh = new ConnectionReadBoundary();
        self::assertNotSame($killedId, Database::withSqlReadBoundary($index, $fresh, $this->currentConnectionId(...)));
        self::assertNull($fresh->failure());
        self::assertSame($returns + 1, Database::returnsAfterLoss());
    }

    /** A loss during memory rollback must leave the original RELEASE failure visible to the caller. */
    public function testFailedReleaseKeepsItsSqlFailureWhenRollbackCallbackLosesTheLink(): void
    {
        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;
        $readerId = $this->currentConnectionId();
        $boundary = new ConnectionReadBoundary();
        $returns = Database::returnsAfterLoss();

        Database::withSqlReadBoundary($index, $boundary, function () use ($index, $readerId, $boundary, $returns): void {
            $restored = false;
            $announced = false;
            $connectedBeforeKill = false;
            Database::transactionStartNestable($index);
            try {
                Database::transactionStartNestable($index);
                self::assertSame($readerId, $this->currentConnectionId());
                Database::sql('RELEASE SAVEPOINT hilos_tx_2', index: $index);
                Database::afterCommit(static function () use (&$announced): void { $announced = true; }, $index);
                Database::onRollback(static function () use (&$restored): void { $restored = true; }, $index);
                Database::onRollback(function () use ($index, $readerId, $boundary, &$connectedBeforeKill): void {
                    $connectedBeforeKill = Database::isConnected($index) && $boundary->failure() === null;
                    $this->killConnection($readerId);
                    Database::sql('SELECT 1 AS rollback_probe', index: $index);
                }, $index);

                try {
                    Database::transactionCommit($index);
                    self::fail('The missing savepoint must refuse RELEASE');
                } catch (DatabaseRuntimeException $failure) {
                    self::assertSame(DatabaseRuntimeException::class, $failure::class);
                    self::assertSame(1305, $failure->getCode());
                    self::assertSame('RELEASE SAVEPOINT', $failure->getQuery());
                }

                self::assertTrue($connectedBeforeKill);
                self::assertTrue($restored);
                $loss = $boundary->failure();
                self::assertTrue($loss instanceof GoneAwayException || $loss instanceof LostConnectionException);
                self::assertSame('SELECT 1 AS rollback_probe', $loss->getQuery());

                self::assertTrue(Database::hasOpenTransactions());
                Database::transactionRollback($index);
                self::assertTrue(Database::hasOpenTransactions(), 'Rolling back the failed child must leave its parent open');
                Database::transactionRollback($index);
            } finally {
                Database::transactionRollbackSilent($index);
                Database::transactionRollbackSilent($index);
            }
            self::assertFalse(Database::hasOpenTransactions());
            self::assertFalse($announced);

            try {
                Database::sql('SELECT 1', index: $index);
                self::fail('The next read must leave the link closed');
            } catch (GoneAwayException | LostConnectionException $failure) {
                self::assertSame($loss, $failure);
            }
            self::assertFalse(Database::isConnected($index));
            self::assertSame($returns, Database::returnsAfterLoss());
        });

        $fresh = new ConnectionReadBoundary();
        self::assertNotSame($readerId, Database::withSqlReadBoundary($index, $fresh, $this->currentConnectionId(...)));
        self::assertNull($fresh->failure());
        self::assertSame($returns + 1, Database::returnsAfterLoss());
    }

    /** A local refusal followed by a successful rollback leaves the same delivery open. */
    public function testSuccessfulSnapshotRollbackKeepsTheReadBoundaryOpen(): void
    {
        $boundary = new ConnectionReadBoundary();
        $refusal = new DbCollectionNotReadableException('The source is not wired');
        $connectionId = $this->currentConnectionId();

        Database::withSqlReadBoundary(
            DatabaseConnectionDefaults::PRIMARY_INDEX,
            $boundary,
            function () use ($boundary, $refusal, $connectionId): void {
                try {
                    Database::readSnapshot(DatabaseNames::PRIMARY, function () use ($refusal, $connectionId): never {
                        self::assertSame($connectionId, $this->currentConnectionId());
                        throw $refusal;
                    });
                    self::fail('The snapshot must raise the local refusal');
                } catch (DbCollectionNotReadableException $failure) {
                    self::assertSame($refusal, $failure);
                }

                self::assertNull($boundary->failure());
                self::assertSame($connectionId, $this->currentConnectionId());
            },
        );
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
     * The first statement after a lost connection is one return; the statements after it are none.
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     * @throws EnvException When DB env variables are missing or invalid.
     */
    public function testAQueryAfterALostConnectionCountsOneReturn(): void
    {
        $before = $this->settledReturnCount();
        $this->killConnection($this->currentConnectionId());

        Database::sql('SELECT 1');
        $this->assertSame($before + 1, Database::returnsAfterLoss());

        Database::sql('SELECT 1');
        $this->assertSame($before + 1, Database::returnsAfterLoss(), 'Only the first statement after the loss is a return');
    }

    /**
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    public function testQueriesWithoutALossCountNoReturn(): void
    {
        $before = $this->settledReturnCount();

        Database::sql('SELECT 1');
        Database::sql('SELECT 1');
        $this->assertSame($before, Database::returnsAfterLoss());
    }

    /**
     * A statement the server answered with an error is not the return: the loss waits for one that succeeds.
     *
     * @throws DatabaseException On SQL or connection errors from the database layer.
     * @throws EnvException When DB env variables are missing or invalid.
     */
    public function testAStatementTheServerRefusedDoesNotCountTheReturn(): void
    {
        $before = $this->settledReturnCount();
        $this->killConnection($this->currentConnectionId());

        try {
            Database::sql('SELECT * FROM `' . self::MISSING_TABLE . '`');
            self::fail('The missing table must refuse the statement');
        } catch (TableNotFoundException) {
            // The reconnect reached the server, and the server refused the statement.
        }
        $this->assertSame($before, Database::returnsAfterLoss());

        Database::sql('SELECT 1');
        $this->assertSame($before + 1, Database::returnsAfterLoss());
    }

    /**
     * An open that did not reach the server is a loss too, and the first statement through the slot pays it.
     *
     * @throws DatabaseException When the connection or query fails unexpectedly
     * @throws EnvException When DB env variables are unavailable
     */
    public function testAFailedOpenThenAQueryCountsOneReturn(): void
    {
        $before = $this->settledReturnCount();
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
            // The open never reached a server: the slot is marked, and nothing is counted yet.
        }
        $this->assertSame($before, Database::returnsAfterLoss());

        Database::configure(
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            port: Hilos::$env[EnvConstants::DB_PORT]->int(),
        );
        Database::sql('SELECT 1');
        $this->assertSame($before + 1, Database::returnsAfterLoss());
    }

    /**
     * @throws DatabaseException When the connection or query fails
     */
    public function testAConnectionClosedByHandIsNotALoss(): void
    {
        $before = $this->settledReturnCount();

        Database::close();
        Database::connect();
        Database::sql('SELECT 1');
        $this->assertSame($before, Database::returnsAfterLoss());
    }

    /**
     * Pays a loss an earlier case left unpaid, so the count read here moves only by what this case does.
     *
     * The marks and the count belong to the process, not to the case: a case that ends on a lost or
     * refused connection leaves its mark for the next statement, and that statement may be this case's.
     *
     * @return int Returns counted in this process so far
     * @throws DatabaseException On SQL or connection errors from the database layer.
     */
    private function settledReturnCount(): int
    {
        Database::sql('SELECT 1');

        return Database::returnsAfterLoss();
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

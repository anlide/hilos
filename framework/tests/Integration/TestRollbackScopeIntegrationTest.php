<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionLeftOpenException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Database\SqlBindType;
use Hilos\Database\SqlParam;
use Hilos\Database\Transaction\TestRollbackScope;
use Hilos\Database\View\Item\AccountDeletion;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use mysqli;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;

/**
 * Technical, unmarked tests of the rollback harness itself. Stubs and the persisted baseline are
 * prepared before the scope. A separate autocommit mysqli session verifies persisted rows without
 * the ORM cache or Database's scope; it also kills a live connection to exercise actual failure.
 */
final class TestRollbackScopeIntegrationTest extends HilosSessionIntegrationTestCase
{
    private ?TestRollbackScope $scope = null;
    private ?mysqli $observer = null;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRt = null;
    private RollbackScopeReaction $reaction;

    /** Builds unchanged migration stubs outside the scope and installs real sync and reaction readers. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->reaction = new RollbackScopeReaction();
        SourceChangeBus::subscribe($this->reaction);
        Database::sqlRun(
            "INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at) VALUES (100, '2026-01-01', '2027-01-01')",
        );
        $config = Database::getConnectionConfig(0);
        $this->observer = new mysqli($config->host, $config->user, $config->password, $config->database, $config->port);
    }

    /** Always closes the scope before the parent drops stubs, including after a failed assertion. */
    protected function tearDown(): void
    {
        try {
            $this->scope?->rollback();
        } finally {
            $this->observer?->close();
            SourceChangeBus::reset();
            Hilos::$sr = $this->previousRouter;
            Hilos::$rt = $this->previousRt;
            Database::useConnection(0);
            if (!Database::isConnected()) {
                Database::connect();
            }
            parent::tearDown();
        }
    }

    /** A real plain method transaction becomes a savepoint, and all its announcements stay held. */
    public function testSuccessfulMethodCommitRemainsInvisibleAndCleanupRestoresMemory(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        Database::transactionStart();
        $request = self::request(1562);
        Database::transactionCommit();
        self::assertSame([100, 1562], self::localUsers());
        self::assertSame([100], $this->persistedUsers());
        self::assertNotNull(Hilos::$db->accountDeletions->liveOf(1562));
        $this->assertNothingPublished();

        $this->scope->rollback();

        self::assertSame([100], $this->persistedUsers());
        self::assertNull($request->id, 'The same ORM object is new again');
        self::assertNull(Hilos::$db->accountDeletions->liveOf(1562));
        self::assertSame([], Database::handlerEnd());
        $this->assertNothingPublished();
    }

    /** Inner rollback undoes its savepoint while direct writes still belong to the root. */
    public function testInnerRollbackPreservesOnlyTheRootWritesUntilCleanup(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        self::request(1562);
        Database::transactionStart();
        $request = self::request(1563);
        Database::transactionRollback();
        self::assertNull($request->id);
        self::assertSame([100, 1562], self::localUsers());
        self::assertSame([100], $this->persistedUsers());
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
        $this->assertNothingPublished();
    }

    /** @return iterable<string, array{bool}> Method exception and assertion failure */
    public static function caseFailures(): iterable
    {
        yield 'exception' => [false];
        yield 'assertion' => [true];
    }

    /** @param bool $assertion Whether the test assertion fails after the method commits */
    #[DataProvider('caseFailures')]
    public function testCleanupAfterAnExceptionOrAssertionLeavesNoFixture(bool $assertion): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        try {
            try {
                Database::transactionStart();
                try {
                    self::request(1562);
                    if (!$assertion) {
                        throw new RuntimeException('method failed');
                    }
                    Database::transactionCommit();
                } catch (RuntimeException $failure) {
                    Database::transactionRollback();
                    throw $failure;
                }
                self::fail('assertion failed');
            } finally {
                $this->scope->rollback();
            }
        } catch (RuntimeException|AssertionFailedError $failure) {
            self::assertSame($assertion ? 'assertion failed' : 'method failed', $failure->getMessage());
        }
        self::assertSame([100], $this->persistedUsers());
        self::assertFalse(Database::hasOpenTransactions());
        $this->assertNothingPublished();
    }

    /** A refused RELEASE never publishes, even when its savepoint can no longer be rolled back. */
    public function testFailedReleaseDropsMemoryAndAnnouncementsAndTheScopeRemovesItsRows(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        Database::transactionStart();
        $request = self::request(1562);
        // Deliberate driver sabotage in this unmarked mechanism test, not an alternate transaction stack.
        $handle = new ReflectionProperty(Database::class, 'connections')->getValue()[0];
        mysqli_release_savepoint($handle, 'hilos_tx_2');
        try {
            Database::transactionCommit();
            self::fail('RELEASE of the missing savepoint must fail');
        } catch (DatabaseRuntimeException) {
            self::assertNull($request->id);
        }
        Database::transactionRollback();
        $this->assertNothingPublished();
        self::assertSame([100], $this->persistedUsers());
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
        self::assertSame([100], self::localUsers());
        $this->assertNothingPublished();
    }

    /** Neither bare end operation reaches the physical root; plain application nesting still refuses. */
    public function testRootProtectionAndPlainNestingAgainstRealSql(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        self::request(1562);
        Database::transactionRollback();
        try {
            Database::transactionCommit();
            self::fail('A root is not an application transaction');
        } catch (TransactionNotOpenException) {
            self::assertSame([100, 1562], self::localUsers());
        }
        Database::transactionStart();
        self::request(1563);
        try {
            Database::transactionStart();
            self::fail('Plain nested application start must refuse');
        } catch (NestedTransactionRefusedException) {
            Database::transactionRollback();
        }
        self::assertSame([100, 1562], self::localUsers());
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
    }

    /** Each configured index gets its own physical root, irrespective of the index at cleanup. */
    public function testTwoConnectionRootsRollbackAndKeepTheOriginalCurrentIndex(): void
    {
        self::configureSecondConnection();
        $this->scope = Database::beginRollbackScopeForTest();
        self::request(1562);
        Database::useConnection(2);
        Database::transactionStart();
        self::request(1563);
        Database::transactionCommit();
        self::assertSame([100, 1563], self::localUsers());
        self::assertSame([100], $this->persistedUsers());
        $this->scope->rollback();
        self::assertSame(2, Database::getCurrentIndex());
        self::assertSame([100], $this->persistedUsers());
        self::assertFalse(Database::hasOpenTransactions());
        $this->assertNothingPublished();
    }

    /** An unused configured link stays closed when the scope touched only its memory callbacks. */
    public function testMemoryOnlySecondaryRootNeedsNoConnection(): void
    {
        self::configureSecondConnection();
        $this->scope = Database::beginRollbackScopeForTest();
        Database::useConnection(2);
        $undone = false;
        Database::onRollback(static function () use (&$undone): void { $undone = true; });
        Database::afterCommit(static function (): void { self::fail('Memory-only callback must stay held'); });
        $this->scope->rollback();
        self::assertTrue($undone);
        self::assertFalse(Database::isConnected(2));
        self::assertSame(2, Database::getCurrentIndex());
    }

    /** A multi-query is admitted as a whole, before even its first allowed write is sent. */
    public function testForbiddenSqlNeverPartiallyExecutes(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        foreach (['COMMIT', 'ROLLBACK', 'BEGIN', 'START TRANSACTION', 'SAVEPOINT x', 'RELEASE SAVEPOINT x',
            'CREATE TABLE rollback_scope_bad (id INT)', 'TRUNCATE hilos_account_deletion', 'SET autocommit=1',
            'LOCK TABLES hilos_account_deletion WRITE', 'UNLOCK TABLES', 'CALL unsupported()', '/*! COMMIT */'] as $sql) {
            try {
                Database::sqlRun(
                    "INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at) VALUES (999, NOW(), NOW()); " . $sql,
                );
                self::fail('Unsupported statement must refuse: ' . $sql);
            } catch (DatabaseRuntimeException) {
                self::assertSame([100], self::localUsers());
            }
        }
        Database::sqlRun("/* COMMIT; */ SELECT 'a;COMMIT', 'it\\'s; ROLLBACK', `user_id` FROM hilos_account_deletion; -- tail\n SELECT 2");
        Database::sql("SELECT ? AS literal", ["it's; COMMIT"]);
        self::assertSame("it's; COMMIT", Database::field('literal'));
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
    }

    /** @return iterable<string, array{string, string, ?array, string}> Session mode, query, parameters and result key */
    public static function quotedSqlModes(): iterable
    {
        yield 'ordinary literal' => ['', "SELECT 'tail\\\\' AS literal; SELECT 2 AS later", null, 'literal'];
        yield 'no backslash escapes literal' => [
            'NO_BACKSLASH_ESCAPES', "SELECT 'tail\\' AS literal; SELECT 2 AS later", null, 'literal',
        ];
        yield 'ansi identifier' => ['ANSI_QUOTES', 'SELECT \'tail\\\\\' AS "tail\\"; SELECT 2 AS later', null, 'tail\\'];
        yield 'both modes' => [
            'ANSI_QUOTES,NO_BACKSLASH_ESCAPES', 'SELECT \'tail\\\' AS "tail\\"; SELECT 2 AS later', null, 'tail\\',
        ];
        foreach (['', 'NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES', 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES'] as $mode) {
            yield 'bound ' . $mode => [$mode, 'SELECT ? AS literal; SELECT 2 AS later', ['tail\\'], 'literal'];
        }
    }

    /**
     * @param string $mode Session syntax installed before the scope
     * @param string $sql Valid multi-query in that mode
     * @param ?array $params Bound values, when the query uses placeholders
     * @param string $key Result key, including a trailing backslash in ANSI identifiers
     */
    #[DataProvider('quotedSqlModes')]
    public function testValidQuotedSqlUsesTheActualSessionMode(string $mode, string $sql, ?array $params, string $key): void
    {
        Database::sqlRun('SET SESSION sql_mode = ?', [$mode]);
        $this->scope = Database::beginRollbackScopeForTest();
        self::assertSame([[$key => 'tail\\']], Database::sql($sql, $params)->rows());
        self::assertTrue(Database::nextResult());
        self::assertSame(2, (int)Database::field('later'));
        self::assertFalse(Database::nextResult());
        self::assertSame(
            [['literal' => "it's; COMMIT; tail\\"]],
            Database::sql('SELECT ? AS literal', ["it's; COMMIT; tail\\"])->rows(),
        );
        self::request(1562);
        self::assertSame([100, 1562], self::localUsers());
        self::assertSame([100], $this->persistedUsers());
        $this->scope->rollback();
        self::assertSame([100], self::localUsers());
        self::assertSame([100], $this->persistedUsers());
    }

    /** @return iterable<string, array{string, string}> Session mode and mode-dependent forbidden tail */
    public static function forbiddenSqlModes(): iterable
    {
        foreach (['COMMIT', 'CREATE TABLE rollback_scope_bad (id INT)'] as $statement) {
            yield 'no backslash escapes ' . $statement => ['NO_BACKSLASH_ESCAPES', "SELECT '\\'; " . $statement . "; -- '"];
            yield 'ansi quotes ' . $statement => ['ANSI_QUOTES', 'SELECT 1 AS "\\"; ' . $statement . '; -- "'];
        }
    }

    /**
     * @param string $mode Session syntax installed before the scope
     * @param string $tail Forbidden statement exposed by that syntax
     */
    #[DataProvider('forbiddenSqlModes')]
    public function testActualModeRefusesBeforeTheFirstWrite(string $mode, string $tail): void
    {
        Database::sqlRun('SET SESSION sql_mode = ?', [$mode]);
        $this->scope = Database::beginRollbackScopeForTest();
        try {
            Database::sqlRun(
                'INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at) VALUES (999, NOW(), NOW()); ' . $tail,
            );
            self::fail('The whole multi-query must refuse before its first write');
        } catch (DatabaseRuntimeException) {
            self::assertSame([100], self::localUsers());
            self::assertSame([100], $this->persistedUsers());
        }
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
    }

    /** A deliberately mistyped parameter cannot add transaction control after template admission. */
    public function testRenderedParametersAreCheckedBeforeTheFirstWrite(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        try {
            Database::sqlRun(
                'INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at) VALUES (999, NOW(), NOW()); SELECT ?',
                [new SqlParam('1; COMMIT', SqlBindType::INTEGER)],
            );
            self::fail('Rendered SQL must refuse before its first write');
        } catch (DatabaseRuntimeException) {
            self::assertSame([100], self::localUsers());
            self::assertSame([100], $this->persistedUsers());
        }
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
    }

    /** The session's preexisting SQL mode cannot make a semicolon escape its quoted value. */
    public function testNoBackslashEscapesCannotBypassAdmission(): void
    {
        Database::sqlRun("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");
        $this->scope = Database::beginRollbackScopeForTest();
        self::request(1562);
        try {
            Database::sqlRun("SELECT '\\'; COMMIT; -- '");
            self::fail('The actual session mode must expose and refuse COMMIT');
        } catch (DatabaseRuntimeException) {
            self::assertSame([100], $this->persistedUsers());
        }
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
    }

    /** Double quotes obey ANSI_QUOTES even when single-quoted strings still use backslashes. */
    public function testAnsiQuotesCannotBypassAdmission(): void
    {
        Database::sqlRun("SET SESSION sql_mode = 'ANSI_QUOTES'");
        $this->scope = Database::beginRollbackScopeForTest();
        self::request(1562);
        try {
            Database::sqlRun('SELECT "\\"; COMMIT; -- "');
            self::fail('The identifier quote must expose and refuse COMMIT');
        } catch (DatabaseRuntimeException) {
            self::assertSame([100], $this->persistedUsers());
        }
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
    }

    /** Unread results cannot prevent cleanup of an admitted multi-query. */
    public function testCleanupDrainsRemainingMultiQueryResults(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        Database::sql(
            "INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at) VALUES (1562, NOW(), NOW()); SELECT 1; SELECT 2",
        );
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
    }

    /** A statement failure deferred by multi-query is reported after the successful write is rolled back. */
    public function testCleanupReportsDeferredSqlFailureAfterRollingBack(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        Database::sql(
            "INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at) VALUES (1562, NOW(), NOW()); SELECT missing_column",
        );
        try {
            $this->scope->rollback();
            self::fail('The deferred SQL failure must fail cleanup');
        } catch (TestRollbackRefusedException) {
            self::assertSame([100], $this->persistedUsers());
        }
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** Admission checks every index, including an open transaction on a noncurrent connection. */
    public function testAnExistingTransactionOnAnotherConnectionRefusesAdmission(): void
    {
        self::configureSecondConnection();
        Database::useConnection(2);
        Database::transactionStart();
        Database::useConnection(0);
        try {
            Database::beginRollbackScopeForTest();
            self::fail('The secondary application transaction must refuse admission');
        } catch (TestRollbackRefusedException) {
            self::assertSame(0, Database::getCurrentIndex());
            self::assertFalse(Database::isConnected(2));
        } finally {
            Database::useConnection(2);
            Database::transactionRollback();
        }
    }

    /** @return iterable<string, array{bool}> Explicit close and reconfiguration both lose the physical root */
    public static function lostConnections(): iterable
    {
        yield 'close' => [false];
        yield 'reconfigure' => [true];
    }

    /** @param bool $reconfigure Whether configuration replaces the old connection */
    #[DataProvider('lostConnections')]
    public function testExplicitConnectionLossCannotFallBackToAutocommit(bool $reconfigure): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        $request = self::request(1562);
        if ($reconfigure) {
            $config = Database::getConnectionConfig(0);
            Database::configure(0, $config->host, $config->user, $config->password, $config->database, $config->port);
        } else {
            Database::close();
        }
        self::assertNull($request->id);
        Database::transactionRollback();
        try {
            self::request(1563);
            self::fail('The failed root must refuse another write');
        } catch (DatabaseRuntimeException) {
            self::assertSame([100], $this->persistedUsers());
        }
        try {
            $this->scope->rollback();
            self::fail('Losing the physical transaction must fail the case');
        } catch (TestRollbackRefusedException) {
            self::assertFalse(Database::hasOpenTransactions());
        }
        $this->assertNothingPublished();
    }

    /** The first failed connection cannot prevent rolling back a healthy second connection. */
    public function testLostConnectionRefusesFurtherWritesAndCleanupStillClosesEveryRoot(): void
    {
        self::configureSecondConnection();
        $this->scope = Database::beginRollbackScopeForTest();
        $first = self::request(1562);
        Database::sql('SELECT CONNECTION_ID() AS id');
        $connectionId = (int)Database::field('id');
        Database::useConnection(2);
        self::request(1563);
        Database::useConnection(0);
        $this->observer->query('KILL CONNECTION ' . $connectionId);
        try {
            Database::sql('SELECT 1');
            self::fail('Lost transaction must refuse SQL');
        } catch (DatabaseRuntimeException) {
            self::assertNull($first->id, 'Connection loss restores the ORM immediately');
        }
        Database::transactionRollback();
        try {
            Database::connect();
            self::fail('A failed root must refuse replacement connections');
        } catch (DatabaseConnectionException) {
            self::assertFalse(Database::isConnected());
        }
        try {
            self::request(1564);
            self::fail('The next write must not run in autocommit');
        } catch (DatabaseRuntimeException) {
            $this->assertNothingPublished();
        }
        try {
            $this->scope->rollback();
            self::fail('A lost root must fail cleanup');
        } catch (TestRollbackRefusedException) {
            self::assertSame([100], $this->persistedUsers());
        }
        self::assertSame(0, Database::getCurrentIndex());
        self::assertFalse(Database::hasOpenTransactions());
        $this->assertNothingPublished();
    }

    /** A failed driver rollback during cleanup still allows the next connection to be cleaned. */
    public function testCleanupDriverFailureStillRollsBackTheOtherConnection(): void
    {
        self::configureSecondConnection();
        $this->scope = Database::beginRollbackScopeForTest();
        self::request(1562);
        Database::sql('SELECT CONNECTION_ID() AS id');
        $connectionId = (int)Database::field('id');
        Database::useConnection(2);
        self::request(1563);
        $this->observer->query('KILL CONNECTION ' . $connectionId);
        try {
            $this->scope->rollback();
            self::fail('The killed connection cannot confirm its rollback');
        } catch (TestRollbackRefusedException) {
            self::assertSame([100], $this->persistedUsers());
        }
        self::assertSame(2, Database::getCurrentIndex());
        self::assertFalse(Database::hasOpenTransactions());
        $this->assertNothingPublished();
    }

    /** The token reports a leaked application transaction only after its rows and memory are gone. */
    public function testLeftOpenApplicationAndRepeatedCleanupCannotDamageTheNextCase(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        Database::transactionStart();
        $request = self::request(1562);
        try {
            $this->scope->rollback();
            self::fail('The application transaction was left open');
        } catch (TransactionLeftOpenException) {
            self::assertNull($request->id);
        }
        self::assertSame([100], $this->persistedUsers());
        $oldScope = $this->scope;
        $this->scope = Database::beginRollbackScopeForTest();
        self::request(1563);
        $oldScope->rollback();
        self::assertSame([100, 1563], self::localUsers());
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
        $this->assertNothingPublished();
    }

    /** A handler reports only the leaked application level and preserves the expected test root. */
    public function testHandlerEndKeepsTheRootForItsToken(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        self::request(1562);
        Database::transactionStart();
        self::request(1563);
        $owed = Database::handlerEnd();
        self::assertCount(1, $owed);
        self::assertInstanceOf(TransactionLeftOpenException::class, $owed[0]);
        self::assertSame([100, 1562], self::localUsers());
        self::assertSame([], Database::handlerEnd());
        $this->scope->rollback();
        self::assertSame([100], $this->persistedUsers());
        $this->assertNothingPublished();
    }

    /** @param int $userId Fixture person number
     * @return AccountDeletion Created request through the framework's real write doors
     */
    private static function request(int $userId): AccountDeletion
    {
        return Hilos::$db->accountDeletions->actions->request($userId, '2027-01-01 00:00:00');
    }

    /** @return list<int> Users visible to the current transactional connection, bypassing ORM caches */
    private static function localUsers(): array
    {
        Database::sql('SELECT user_id FROM hilos_account_deletion ORDER BY user_id');
        $users = [];
        while (($row = Database::row()) !== null) {
            $users[] = (int)$row['user_id'];
        }
        return $users;
    }

    /** @return list<int> Persisted users on an independent autocommit reader */
    private function persistedUsers(): array
    {
        $result = $this->observer->query('SELECT user_id FROM hilos_account_deletion ORDER BY user_id');
        $users = array_map(static fn (array $row): int => (int)$row[0], $result->fetch_all());
        $result->free();
        return $users;
    }

    /** Both the actual queued DB frames and the source bus reactions must remain absent. */
    private function assertNothingPublished(): void
    {
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertSame([], $this->reaction->seen);
    }

    /** Adds a second lazy connection to the same fixture database. */
    private static function configureSecondConnection(): void
    {
        $config = Database::getConnectionConfig(0);
        Database::configure(2, $config->host, $config->user, $config->password, $config->database, $config->port);
    }
}

/** Records real source bus reactions, which a rollback scope must never release. */
final class RollbackScopeReaction implements SourceChangeSubscriberInterface
{
    /** @var list<string> Source ids delivered to a reaction */
    public array $seen = [];

    /**
     * @param SourceChange $change Source fact being published
     * @param SourceChangeProvenance $provenance Origin, irrelevant to the no-publication assertion
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $change->sourceId;
    }
}

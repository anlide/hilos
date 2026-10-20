<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionLeftOpenException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Database\Transaction\TestRollbackScope;
use Hilos\Database\Transaction\TestRollbackSqlGuard;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/** Pure admission, token ownership and lazy memory-only transactions; no SQL connection is needed. */
final class TestRollbackScopeTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private string|false $previousAppEnv = false;
    private ?TestRollbackScope $scope = null;

    /** Installs a known test environment without connecting to SQL. */
    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousAppEnv = getenv('APP_ENV');
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    /** Closes the token before the handler and restores the environment. */
    protected function tearDown(): void
    {
        try {
            $this->scope?->rollback();
        } finally {
            Database::handlerEnd();
            Hilos::$env = $this->previousEnv;
            $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
        }
    }

    /** @return iterable<string, array{?string}> Environments that must fail closed */
    public static function refusedEnvironments(): iterable
    {
        yield 'absent accessor' => [null];
        yield 'production' => ['production'];
        yield 'staging' => ['staging'];
        yield 'unknown' => ['not-an-environment'];
        yield 'empty' => [''];
    }

    /** @param ?string $environment Environment under test */
    #[DataProvider('refusedEnvironments')]
    public function testEnvironmentRefusalChangesNothing(?string $environment): void
    {
        if ($environment === null) {
            Hilos::$env = null;
        } else {
            putenv('APP_ENV=' . $environment);
            Hilos::$env = new EnvAccessor();
        }
        try {
            Database::beginRollbackScopeForTest();
            self::fail('Environment must refuse');
        } catch (TestRollbackRefusedException) {
            self::assertFalse(Database::hasOpenTransactions());
        }
    }

    /** A second empty scope refuses even before a connection root is touched. */
    public function testAnEmptyScopeIsExclusiveAndLazy(): void
    {
        $connected = Database::isConnected();
        $index = Database::getCurrentIndex();
        $this->scope = Database::beginRollbackScopeForTest();
        self::assertTrue(Database::hasOpenTransactions());
        self::assertSame($connected, Database::isConnected());
        self::assertSame($index, Database::getCurrentIndex());
        self::assertSame([], Database::handlerEnd());
        try {
            Database::beginRollbackScopeForTest();
            self::fail('A second scope must refuse');
        } catch (TestRollbackRefusedException) {
            $this->scope->rollback();
        }
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** @return iterable<string, array{bool}> Open and failed application levels */
    public static function transactionStates(): iterable
    {
        yield 'open' => [false];
        yield 'failed' => [true];
    }

    /** @param bool $failed Whether the existing level is already failed */
    #[DataProvider('transactionStates')]
    public function testAnyExistingTransactionRefusesAdmission(bool $failed): void
    {
        Database::transactionStart();
        // Inspect the real stack; do not install a replacement or a parallel transaction mechanism.
        $levels = new ReflectionProperty(Database::class, 'transactions')->getValue()[Database::getCurrentIndex()];
        if ($failed) {
            $levels[0]->markFailed();
        }
        try {
            Database::beginRollbackScopeForTest();
            self::fail('An open or failed transaction must refuse');
        } catch (TestRollbackRefusedException) {
            self::assertSame($levels, new ReflectionProperty(Database::class, 'transactions')->getValue()[Database::getCurrentIndex()]);
        } finally {
            Database::transactionRollback();
        }
    }

    /** A cloned capability cannot close the original owner's roots. */
    public function testTokenIdentityAndRepeatedCleanupCannotReachAnotherCase(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        $foreign = clone $this->scope;
        try {
            $foreign->rollback();
            self::fail('A clone is not the owner');
        } catch (TestRollbackRefusedException) {
            self::assertTrue(Database::hasOpenTransactions());
        }
        $first = $this->scope;
        $first->rollback();
        $this->scope = Database::beginRollbackScopeForTest();
        $first->rollback();
        $foreign->rollback();
        self::assertTrue(Database::hasOpenTransactions());
        $this->scope->rollback();
        self::assertFalse(Database::hasOpenTransactions());
        self::assertFalse(method_exists($first, 'commit'));
    }

    /** Committed application work stays private and its memory returns with the root. */
    public function testMemoryOnlyWorkAndApplicationCommitUseTheRoot(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        $announced = [];
        $undone = [];
        Database::afterCommit(static function () use (&$announced): void { $announced[] = 'root'; });
        Database::onRollback(static function () use (&$undone): void { $undone[] = 'root'; });
        Database::transactionStart();
        Database::onRollback(static function () use (&$undone): void { $undone[] = 'application'; });
        Database::afterCommit(static function () use (&$announced): void { $announced[] = 'application'; });
        Database::transactionCommit();
        self::assertSame([], Database::handlerEnd(), 'The expected root is not a leaked application transaction');
        Database::transactionRollback();
        try {
            Database::transactionCommit();
            self::fail('A bare commit cannot commit the test root');
        } catch (TransactionNotOpenException) {
            self::assertSame([], $undone);
        }
        $this->scope->rollback();
        self::assertSame(['application', 'root'], $undone);
        self::assertSame([], $announced);
    }

    /** Only the synthetic root is neutral to plain and nestable start checks. */
    public function testPlainApplicationLevelsStillRefuseNesting(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        Database::transactionStart();
        foreach ([Database::transactionStart(...), Database::transactionStartNestable(...)] as $start) {
            try {
                $start();
                self::fail('A plain application level must refuse nesting');
            } catch (NestedTransactionRefusedException) {
                self::assertTrue(Database::hasOpenTransactions());
            }
        }
        Database::transactionRollback();
        Database::transactionStartNestable();
        Database::transactionStartNestable();
        Database::transactionCommit();
        Database::transactionCommit();
        $this->scope->rollback();
    }

    /** Cleanup restores all memory before reporting an application level left open. */
    public function testLeftOpenApplicationIsRedAfterFullCleanup(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        $undone = [];
        Database::onRollback(static function () use (&$undone): void { $undone[] = 'root'; });
        Database::transactionStart();
        Database::onRollback(static function () use (&$undone): void { $undone[] = 'application'; });
        try {
            $this->scope->rollback();
            self::fail('An unclosed application transaction must fail the test');
        } catch (TransactionLeftOpenException $failure) {
            self::assertStringContainsString('depth 1', $failure->getMessage());
        }
        self::assertSame(['application', 'root'], $undone);
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], Database::handlerEnd());
    }

    /** A failing undo stops neither older undo nor closing the token. */
    public function testCleanupContinuesAfterUndoFailureAndNeverPublishes(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        $undone = false;
        $published = false;
        Database::onRollback(static function () use (&$undone, &$published): void {
            $undone = true;
            Database::afterCommit(static function () use (&$published): void { $published = true; });
        });
        Database::onRollback(static function (): void { throw new RuntimeException('undo failed'); });
        try {
            $this->scope->rollback();
            self::fail('A failed undo must fail cleanup');
        } catch (TestRollbackRefusedException $failure) {
            self::assertSame('undo failed', $failure->getPrevious()?->getMessage());
        }
        self::assertTrue($undone);
        self::assertFalse($published);
        self::assertFalse(Database::hasOpenTransactions());
        $this->scope->rollback();
    }

    /** @return iterable<string, array{string}> Safe statement families and lexical look-alikes */
    public static function admittedSql(): iterable
    {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'WITH', 'SHOW', 'EXPLAIN'] as $family) {
            yield $family => [$family . ' anything'];
        }
        yield 'values' => ["SELECT 'COMMIT; DROP TABLE t', \"ROLLBACK;\", `a;b``c`; SELECT 2"];
        yield 'doubled quote' => ["SELECT 'it''s; COMMIT'; SELECT 2"];
        yield 'escaped quote' => ["SELECT 'it\\'s; COMMIT'; SELECT 2"];
        yield 'comments' => ["/* COMMIT; */ SELECT 1; -- ROLLBACK;\n # DROP;\n SELECT 2"];
        yield 'hint' => ['SELECT /*+ MAX_EXECUTION_TIME(1000) */ 1'];
        yield 'empty' => ['; /* empty */ ;'];
    }

    /** @param string $sql Safe query */
    #[DataProvider('admittedSql')]
    public function testSqlGuardAdmitsValuesAndOrdinaryComments(string $sql): void
    {
        TestRollbackSqlGuard::check($sql);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> Unsupported statements at every multi-query position */
    public static function refusedSql(): iterable
    {
        foreach (['CREATE TABLE t (id INT)', 'ALTER TABLE t ADD x INT', 'DROP TABLE t', 'TRUNCATE t',
            'BEGIN', 'START TRANSACTION', 'COMMIT', 'ROLLBACK', 'SAVEPOINT x', 'RELEASE SAVEPOINT x',
            'SET autocommit=1', 'LOCK TABLES t WRITE', 'UNLOCK TABLES', 'CALL p()', 'GRANT ALL ON t TO u',
            'LOAD DATA INFILE "x" INTO TABLE t'] as $statement) {
            yield $statement => [$statement];
            yield 'later ' . $statement => ['SELECT 1; /* comment */ ' . $statement];
        }
        yield 'mysql executable' => ['SELECT 1; /*!50000 COMMIT */'];
        yield 'mariadb executable' => ['SELECT 1 /*M! ; COMMIT */'];
        yield 'unterminated quote' => ["SELECT 'value"];
        yield 'unterminated comment' => ['SELECT 1 /* tail'];
        yield 'quoted family' => ['`SELECT` 1'];
        yield 'line comment boundary' => ["SELECT 1; -- comment\nCOMMIT"];
    }

    /** @param string $sql Unsafe query */
    #[DataProvider('refusedSql')]
    public function testSqlGuardChecksTheWholeQuery(string $sql): void
    {
        $this->expectException(DatabaseRuntimeException::class);
        TestRollbackSqlGuard::check($sql);
    }

    /** An inherited escape mode cannot hide a command behind a quote. */
    public function testSqlGuardRespectsNoBackslashEscapes(): void
    {
        $this->expectException(DatabaseRuntimeException::class);
        TestRollbackSqlGuard::check("SELECT '\\'; COMMIT; -- '", false);
    }

    /** ANSI double quotes are identifiers and cannot hide a command through a backslash. */
    public function testSqlGuardRespectsAnsiQuotes(): void
    {
        $this->expectException(DatabaseRuntimeException::class);
        TestRollbackSqlGuard::check('SELECT "\\"; COMMIT; -- "', ansiQuotes: true);
    }

    /** An undo cannot start fresh transactions while its owner is disposing the roots. */
    public function testCleanupRefusesReentrantTransactionStarts(): void
    {
        $this->scope = Database::beginRollbackScopeForTest();
        Database::onRollback(static function (): void { Database::transactionStart(); });
        try {
            $this->scope->rollback();
            self::fail('Fresh work during cleanup must refuse');
        } catch (TestRollbackRefusedException $failure) {
            self::assertInstanceOf(NestedTransactionRefusedException::class, $failure->getPrevious());
        }
        self::assertFalse(Database::hasOpenTransactions());
    }
}

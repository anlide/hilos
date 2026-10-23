<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseBinding;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\CrossConnectionWriteException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Database\Transaction\TestRollbackScope;
use Hilos\Database\Transaction\TransactionLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/** Resolves real configuration and Entity metadata without a connection or SQL transport double. */
final class NamedDatabaseTest extends TestCase
{
    private const array CONFIGURATION_PROPERTIES = [
        'configurations', 'databaseBindings', 'connections', 'resultSets', 'lastSql', 'closedByHand', 'currentIndex',
        'transactionIndex', 'transactions', 'handlerOpen', 'announcementFailures',
    ];

    /** @var array<string, mixed> Process configuration to restore after each case */
    private array $previousConfiguration = [];

    /** Isolates the same private configuration maps that configure() changes. */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CONFIGURATION_PROPERTIES as $property) {
            $reflection = new ReflectionProperty(Database::class, $property);
            $this->previousConfiguration[$property] = $reflection->getValue();
            $reflection->setValue(null, match ($property) {
                'currentIndex' => 0,
                'transactionIndex' => null,
                'handlerOpen' => false,
                default => [],
            });
        }
    }

    /** Every path must leave connection handles, results and SQL untouched; restore even after an assertion fails. */
    protected function tearDown(): void
    {
        try {
            foreach (Database::getConfiguredIndices() as $index) {
                self::assertFalse(Database::isConnected($index));
                self::assertNull(Database::getCachedResultSet($index));
            }
            foreach (new ReflectionProperty(Database::class, 'lastSql')->getValue() as $sql) {
                self::assertSame('', $sql);
            }
        } finally {
            foreach ($this->previousConfiguration as $property => $value) {
                new ReflectionProperty(Database::class, $property)->setValue(null, $value);
            }
            parent::tearDown();
        }
    }

    public function testInheritedPrimaryMetadataNeedsNoConfiguration(): void
    {
        self::assertSame('_database', Entity::META_DATABASE);
        self::assertSame('primary', NamedDefaultEntity::getDatabaseName());
        self::assertSame('primary', User::getDatabaseName());
        $binding = NamedDefaultEntity::getDatabase();
        self::assertSame('primary', $binding->name);
        self::assertSame(0, $binding->schemaIndex);
        self::assertSame(0, $binding->connectionIndex);
        self::assertSame([], Database::getConfiguredIndices());
    }

    /** @return iterable<string, array{string}> Physical binding accessors */
    public static function physicalAccessors(): iterable
    {
        yield 'schema name' => ['getDatabaseName'];
        yield 'qualified table' => ['getSqlTableName'];
        yield 'identity' => ['getIdentity'];
    }

    /** @param string $accessor Physical accessor under test */
    #[DataProvider('physicalAccessors')]
    public function testUnconfiguredPrimaryRefusesPhysicalAccess(string $accessor): void
    {
        $binding = NamedDefaultEntity::getDatabase();
        $this->expectException(NamedDatabaseException::class);
        if ($accessor === 'getSqlTableName') {
            $binding->getSqlTableName('rows');
        } else {
            $binding->$accessor();
        }
    }

    /** @return iterable<string, array{class-string<Entity>, string}> Explicit invalid Entity declarations */
    public static function invalidEntities(): iterable
    {
        yield 'empty' => [NamedEmptyEntity::class, ''];
        yield 'unknown' => [NamedUnknownEntity::class, 'unknown'];
    }

    /**
     * @param class-string<Entity> $entity Entity metadata reader
     * @param string $name Declared logical name
     */
    #[DataProvider('invalidEntities')]
    public function testExplicitInvalidMetadataNeverFallsBack(string $entity, string $name): void
    {
        self::assertSame($name, $entity::getDatabaseName());
        $this->expectException(NamedDatabaseException::class);
        $entity::getDatabase();
    }

    /** @return iterable<string, array{string}> Invalid or reserved registration names */
    public static function invalidNames(): iterable
    {
        foreach (['', ' archive', 'archive ', 'two names', 'archive.remote', 'archive-remote', '12', "a\0b", 'primary', 'change_log'] as $name) {
            yield bin2hex($name) => [$name];
        }
    }

    /** @param string $name Invalid logical name */
    #[DataProvider('invalidNames')]
    public function testInvalidAndReservedRegistrationsAreRefused(string $name): void
    {
        Database::configure(database: 'main');
        $this->expectException(NamedDatabaseException::class);
        Database::registerDatabase($name, 0);
    }

    public function testDuplicateRegistrationDoesNotReplaceItsTarget(): void
    {
        Database::configure(database: 'main');
        Database::configure(index: 2, database: 'archive');
        Database::registerDatabase('archive', 2);
        try {
            Database::registerDatabase('archive', 0);
            self::fail('A second registration must be refused');
        } catch (NamedDatabaseException) {
            self::assertSame(2, Database::getDatabase('archive')->schemaIndex);
        }
    }

    /** @return iterable<string, array{int, ?int}> Missing schema or row indices */
    public static function missingIndices(): iterable
    {
        yield 'default row' => [2, null];
        yield 'schema' => [2, 0];
        yield 'row' => [0, 2];
        yield 'negative schema' => [-1, 0];
    }

    /**
     * @param int $schemaIndex Schema index
     * @param ?int $connectionIndex Row index
     */
    #[DataProvider('missingIndices')]
    public function testUnconfiguredIndicesCannotBeRegistered(int $schemaIndex, ?int $connectionIndex): void
    {
        Database::configure(database: 'main');
        $this->expectException(NamedDatabaseException::class);
        Database::registerDatabase('archive', $schemaIndex, $connectionIndex);
    }

    public function testIndependentRemoteBindingAndEntityUseItsOwnConfiguration(): void
    {
        Database::configure(host: 'local.invalid', database: 'main');
        Database::configure(index: 2, host: 'remote.invalid', user: 'remote-user', password: 'remote-secret', database: 'remote', port: 4406);
        Database::useConnection(0);
        Database::registerDatabase('archive', schemaIndex: 2);
        $binding = NamedArchiveEntity::getDatabase();
        self::assertSame('archive', NamedArchiveEntity::getDatabaseName());
        self::assertSame('archive', $binding->name);
        self::assertSame(2, $binding->schemaIndex);
        self::assertSame(2, $binding->connectionIndex);
        self::assertSame('remote', $binding->getDatabaseName());
        self::assertSame('`remote`.`named_rows`', NamedArchiveEntity::getSqlTableName());
        self::assertSame(0, Database::getCurrentIndex());
        self::assertSame('`main`.`named_rows`', NamedDefaultEntity::getSqlTableName());
    }

    public function testSharedRowConnectionUsesSchemaCredentialsOnlyForSchemaAccess(): void
    {
        Database::configure(host: 'same.invalid', user: 'row-user', password: 'row-secret', database: 'main', port: 4406, socket: '/tmp/mysql');
        Database::configure(index: 2, host: 'same.invalid', user: 'schema-user', password: 'schema-secret', database: 'archive',
            port: 4406, socket: '/tmp/mysql');
        Database::registerDatabase('archive', schemaIndex: 2, connectionIndex: 0);
        $binding = Database::getDatabase('archive');
        self::assertSame(2, $binding->schemaIndex);
        self::assertSame(0, $binding->connectionIndex);
        self::assertSame('`archive`.`rows`', $binding->getSqlTableName('rows'));
    }

    /** @return iterable<string, array{string, int, ?string}> Different endpoint coordinates */
    public static function differentEndpoints(): iterable
    {
        yield 'host' => ['remote.invalid', 3306, null];
        yield 'no DNS equivalence' => ['127.0.0.1', 3306, null];
        yield 'port' => ['localhost', 4406, null];
        yield 'socket' => ['localhost', 3306, '/tmp/other.sock'];
    }

    /**
     * @param string $host Schema host
     * @param int $port Schema port
     * @param ?string $socket Schema socket
     */
    #[DataProvider('differentEndpoints')]
    public function testSharedRowConnectionRequiresTheExactEndpoint(string $host, int $port, ?string $socket): void
    {
        Database::configure(host: 'localhost', database: 'main');
        Database::configure(index: 2, host: $host, database: 'archive', port: $port, socket: $socket);
        $this->expectException(NamedDatabaseException::class);
        Database::registerDatabase('archive', 2, 0);
    }

    public function testFailedRegistrationDoesNotReserveTheAliasOrChangeAmbientIndex(): void
    {
        Database::configure(database: 'main');
        Database::configure(index: 2, host: 'remote.invalid', database: 'archive');
        Database::useConnection(2);
        try {
            Database::registerDatabase('archive', 2, 0);
            self::fail('A shared connection across endpoints must be refused');
        } catch (NamedDatabaseException) {
            Database::registerDatabase('archive', 2);
        }
        self::assertSame(2, Database::getDatabase('archive')->connectionIndex);
        self::assertSame(2, Database::getCurrentIndex());
    }

    public function testIdentifiersAreQuotedAsWholeComponentsAndEscapeBackticks(): void
    {
        Database::configure(database: 'schema`name');
        self::assertSame('`schema``name`.`rows``; DROP DATABASE main; --`',
            Database::getDatabase('primary')->getSqlTableName('rows`; DROP DATABASE main; --'));
    }

    /** @return iterable<string, array{string}> Invalid SQL identifiers */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'NUL' => ["bad\0identifier"];
    }

    /** @param string $identifier Invalid table name */
    #[DataProvider('invalidIdentifiers')]
    public function testEmptyOrNulTableIdentifiersAreRefused(string $identifier): void
    {
        Database::configure(database: 'main');
        $this->expectException(NamedDatabaseException::class);
        Database::getDatabase('primary')->getSqlTableName($identifier);
    }

    /** @param string $identifier Invalid physical schema name */
    #[DataProvider('invalidIdentifiers')]
    public function testEmptyOrNulPhysicalSchemaNamesAreRefused(string $identifier): void
    {
        Database::configure(database: $identifier);
        $this->expectException(NamedDatabaseException::class);
        Database::getDatabase('primary')->getDatabaseName();
    }

    public function testIdentityIgnoresAliasCredentialsAndConnectionIndices(): void
    {
        Database::configure(host: 'same.invalid', user: 'first', password: 'first-secret', database: 'shared');
        Database::configure(index: 2, host: 'same.invalid', user: 'second', password: 'second-secret', database: 'shared');
        Database::registerDatabase('archive', 2);
        Database::registerDatabase('shared_rows', 2, 0);
        $identity = Database::getDatabase('primary')->getIdentity();
        self::assertSame($identity, Database::getDatabase('archive')->getIdentity());
        self::assertSame($identity, Database::getDatabase('shared_rows')->getIdentity());
        self::assertStringNotContainsString('first-secret', $identity);
        self::assertStringNotContainsString('second-secret', $identity);
        self::assertStringNotContainsString('archive', $identity);
    }

    /**
     * @param string $host Different host
     * @param int $port Different port
     * @param ?string $socket Different socket
     */
    #[DataProvider('differentEndpoints')]
    public function testIdentityIncludesEveryEndpointCoordinate(string $host, int $port, ?string $socket): void
    {
        Database::configure(host: 'localhost', database: 'shared');
        Database::configure(index: 2, host: $host, database: 'shared', port: $port, socket: $socket);
        Database::registerDatabase('archive', 2);
        self::assertNotSame(Database::getDatabase('primary')->getIdentity(), Database::getDatabase('archive')->getIdentity());
    }

    public function testJournalUsesDerivedSchemaOnPrimaryRowConnection(): void
    {
        Database::configure(database: 'piece_1');
        Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
        $binding = Database::getDatabase(DatabaseNames::CHANGE_LOG);
        self::assertSame('change_log', $binding->name);
        self::assertSame(1, $binding->schemaIndex);
        self::assertSame(0, $binding->connectionIndex);
        self::assertSame('piece_1-change-log', $binding->getDatabaseName());
        self::assertSame('`piece_1-change-log`.`rows`', $binding->getSqlTableName('rows'));
    }

    public function testUnconfiguredJournalHasNoMetadataFallback(): void
    {
        $this->expectException(NamedDatabaseException::class);
        Database::getDatabase(DatabaseNames::CHANGE_LOG);
    }

    public function testJournalRejectsASecondarySchemaWithAnUnrelatedName(): void
    {
        Database::configure(database: 'piece_1');
        Database::configure(index: 1, database: 'unrelated');
        $this->expectException(NamedDatabaseException::class);
        Database::getDatabase(DatabaseNames::CHANGE_LOG);
    }

    public function testJournalCannotUseARemoteSchemaOnThePrimaryRowConnection(): void
    {
        Database::configure(database: 'piece_1');
        Database::configure(index: 1, host: 'remote.invalid', database: ChangeLogDatabase::configuredName());
        $this->expectException(NamedDatabaseException::class);
        Database::getDatabase(DatabaseNames::CHANGE_LOG);
    }

    public function testPrimaryReconfigureClearsAliasesAndBindingsReadTheCurrentPiece(): void
    {
        Database::configure(database: 'piece_1');
        Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
        Database::registerDatabase('archive', 0);
        $primary = Database::getDatabase('primary');
        $journal = Database::getDatabase('change_log');
        $identity = $primary->getIdentity();
        Database::configure(database: 'piece_2');
        Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
        self::assertSame('piece_2', $primary->getDatabaseName());
        self::assertNotSame($identity, $primary->getIdentity());
        self::assertSame('piece_2-change-log', $journal->getDatabaseName());
        self::assertSame('piece_2-change-log', Database::getDatabase('change_log')->getDatabaseName());
        $this->expectException(NamedDatabaseException::class);
        Database::getDatabase('archive');
    }

    public function testSecondaryReconfigurePreservesAliasesAndRefreshesPhysicalIdentity(): void
    {
        Database::configure(database: 'main');
        Database::configure(index: 2, database: 'archive_1');
        Database::registerDatabase('archive', 2);
        $binding = Database::getDatabase('archive');
        $identity = $binding->getIdentity();
        Database::configure(index: 2, database: 'archive_2');
        self::assertSame($binding, Database::getDatabase('archive'));
        self::assertSame('archive_2', $binding->getDatabaseName());
        self::assertNotSame($identity, $binding->getIdentity());
    }

    public function testSharedBindingRejectsAnEndpointChangedAfterRegistration(): void
    {
        Database::configure(database: 'main');
        Database::configure(index: 2, database: 'archive');
        Database::registerDatabase('archive', 2, 0);
        Database::configure(index: 2, host: 'remote.invalid', database: 'archive');
        $this->expectException(NamedDatabaseException::class);
        Database::getDatabase('archive');
    }
    /** Explicit hooks can address primary while the named callback holds its own announcements. */
    public function testNamedTransactionCommitsItsOwnScopeAndReturnsTheCallbackValue(): void
    {
        $this->configureArchive();
        $made = [];
        $value = Database::transaction('archive', static function () use (&$made): object {
            self::assertSame(0, Database::getCurrentIndex());
            Database::afterCommit(static function () use (&$made): void { $made[] = 'scoped'; });
            Database::afterCommit(static function () use (&$made): void { $made[] = 'explicit'; }, 0);
            self::assertSame(['explicit'], $made);
            Database::transactionStart(0);
            Database::onRollback(static function () use (&$made): void { $made[] = 'undo-primary'; }, 0);
            Database::transactionRollback(0);
            self::assertSame(['explicit', 'undo-primary'], $made);

            return new DatabaseBinding('answer', 2, 2);
        });
        self::assertInstanceOf(DatabaseBinding::class, $value);
        self::assertSame('answer', $value->name);
        self::assertSame(['explicit', 'undo-primary', 'scoped'], $made);
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame(0, Database::getCurrentIndex());
        self::assertNull(new ReflectionProperty(Database::class, 'transactionIndex')->getValue());
    }

    /** A callback failure leaves an independent primary level and its undo journal intact. */
    public function testNamedCallbackFailureRollsBackItsMemoryAndRestoresLegacyDefault(): void
    {
        $this->configureArchive();
        Database::transactionStart(0);
        $undone = [];
        Database::onRollback(static function () use (&$undone): void { $undone[] = 'primary'; }, 0);
        $failure = new RuntimeException('callback failed');
        try {
            Database::transaction('archive', static function () use (&$undone, $failure): void {
                Database::onRollback(static function () use (&$undone): void { $undone[] = 'archive'; });
                throw $failure;
            });
            self::fail('The callback must fail');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(['archive'], $undone);
        Database::transactionRollback();
        self::assertSame(['archive', 'primary'], $undone);
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** @return iterable<string, array{bool, bool}> Existing plain, nestable and failed parents */
    public static function existingTransactionStates(): iterable
    {
        yield 'plain' => [false, false];
        yield 'nestable' => [true, false];
        yield 'failed nestable' => [true, true];
    }

    /**
     * @param bool $nestable Whether the parent permits marked nesting
     * @param bool $failed Whether the parent has already failed
     */
    #[DataProvider('existingTransactionStates')]
    public function testNamedStartFailureNeverRollsBackTheExistingLevel(bool $nestable, bool $failed): void
    {
        $this->configureArchive();
        if ($nestable) {
            Database::transactionStartNestable(2);
        } else {
            Database::transactionStart(2);
        }
        $levels = new ReflectionProperty(Database::class, 'transactions')->getValue()[2];
        if ($failed) {
            $levels[0]->markFailed();
        }
        try {
            Database::transaction('archive', static fn (): never => throw new RuntimeException('must not run'));
            self::fail('Named transactions never nest');
        } catch (NestedTransactionRefusedException) {
            self::assertSame($levels, new ReflectionProperty(Database::class, 'transactions')->getValue()[2]);
            self::assertNull(new ReflectionProperty(Database::class, 'transactionIndex')->getValue());
        }
        Database::transactionRollbackSilent(2);
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** A completed or failed inner scope cannot take the outer callback's transaction default. */
    public function testInnerNamedScopeRestoresTheOuterScopeAfterSuccessAndFailure(): void
    {
        $this->configureArchive();
        $made = [];
        Database::transaction('archive', static function () use (&$made): void {
            Database::transaction('primary', static function () use (&$made): void {
                Database::afterCommit(static function () use (&$made): void { $made[] = 'inner'; });
            });
            self::assertSame(['inner'], $made);
            try {
                Database::transaction('primary', static fn (): never => throw new RuntimeException('inner failure'));
                self::fail('The inner callback must fail');
            } catch (RuntimeException) {
                Database::afterCommit(static function () use (&$made): void { $made[] = 'outer'; });
            }
            self::assertSame(['inner'], $made);
        });
        self::assertSame(['inner', 'outer'], $made);
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** Cleanup recognizes the committed level by identity, even when an announcement opens a replacement. */
    public function testCommitAnnouncementFailureCannotRollBackANewLevelOpenedByTheAnnouncement(): void
    {
        $this->configureArchive();
        $undone = false;
        try {
            Database::transaction('archive', static function () use (&$undone): void {
                Database::afterCommit(static function () use (&$undone): never {
                    Database::transactionStart();
                    Database::onRollback(static function () use (&$undone): void { $undone = true; });
                    throw new RuntimeException('announcement failed');
                });
            });
            self::fail('The released announcement must fail');
        } catch (AnnouncementFailedException $failure) {
            self::assertSame('announcement failed', $failure->getPrevious()?->getMessage());
            self::assertFalse($undone, 'The committed level is gone; the new level belongs to its caller');
            self::assertNull(new ReflectionProperty(Database::class, 'transactionIndex')->getValue());
        }
        Database::transactionRollback(2);
        self::assertTrue($undone);
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** A callback that replaces its level cannot make the wrapper commit somebody else's work. */
    public function testCallbackCannotMakeTheWrapperCommitAReplacementLevel(): void
    {
        $this->configureArchive();
        try {
            Database::transaction('archive', static function (): void {
                Database::transactionRollback();
                Database::transactionStartNestable();
            });
            self::fail('The wrapper must refuse a replaced application level');
        } catch (TransactionNotOpenException) {
            self::assertCount(1, new ReflectionProperty(Database::class, 'transactions')->getValue()[2]);
        }
        Database::transactionRollback(2);
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** Schema aliases do not introduce transaction stacks apart from their row connection. */
    public function testDifferentSchemasOnOneRowConnectionShareTheSameTransactionStack(): void
    {
        $this->configureArchive();
        Database::registerDatabase('shared_rows', 2, 0);
        Database::transaction('primary', static function (): void {
            try {
                Database::transaction('shared_rows', static fn (): null => null);
                self::fail('Different schemas on one connection cannot open independent transactions');
            } catch (NestedTransactionRefusedException) {
                Database::assertCanWrite(0);
            }
        });
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** @return iterable<string, array{string}> All application states forbidden to a foreign writer */
    public static function applicationStates(): iterable
    {
        yield 'lazy BEGIN' => ['lazy'];
        yield 'opened' => ['opened'];
        yield 'failed' => ['failed'];
    }

    /** @param string $state Application level state */
    #[DataProvider('applicationStates')]
    public function testCrossConnectionGuardRefusesEveryForeignApplicationState(string $state): void
    {
        $this->configureArchive();
        Database::assertCanWrite(0);
        Database::assertCanWrite(2);
        Database::transactionStart(2);
        $level = new ReflectionProperty(Database::class, 'transactions')->getValue()[2][0];
        if ($state === 'opened') {
            $level->markOpened();
        } elseif ($state === 'failed') {
            $level->markFailed();
        }
        Database::assertCanWrite(2);
        try {
            Database::assertCanWrite(0);
            self::fail('A foreign application transaction must refuse a write');
        } catch (CrossConnectionWriteException $failure) {
            self::assertSame(
                'Cannot write on connection 0 while an application transaction remains open on connection 2',
                $failure->getMessage(),
            );
        } finally {
            $level->markFailed();
            Database::transactionRollback(2);
        }
        Database::assertCanWrite(0);
    }

    /** The guard distinguishes a synthetic root from the ordinary application level under it. */
    public function testSyntheticRootsAreNeutralButTheirApplicationChildrenStillRefuseWrites(): void
    {
        $this->configureArchive();
        $root = new TransactionLevel(false, null, new TestRollbackScope(null));
        new ReflectionProperty(Database::class, 'transactions')->setValue(null, [2 => [$root]]);
        Database::assertCanWrite(0);
        Database::assertCanWrite(2);
        Database::transaction('archive', static function (): void {
            Database::assertCanWrite(2);
            try {
                Database::assertCanWrite(0);
                self::fail('A child of the synthetic root is an application transaction');
            } catch (CrossConnectionWriteException) {
                self::assertSame(0, Database::getCurrentIndex());
            }
        });
        self::assertSame([2 => [$root]], new ReflectionProperty(Database::class, 'transactions')->getValue());
        Database::assertCanWrite(0);
    }

    /** @return iterable<string, array{int, int, bool}> Slots and row targets that reconfiguration must preserve */
    public static function reconfigurationTargets(): iterable
    {
        yield 'primary' => [0, 0, false];
        yield 'row target' => [2, 2, false];
        yield 'shared schema' => [3, 2, false];
        yield 'derived journal schema' => [1, 0, false];
        yield 'active slot one' => [1, 1, false];
        yield 'project schema in slot one' => [1, 2, false];
        yield 'primary reset with named target' => [0, 2, false];
        yield 'failed row target' => [2, 2, true];
        yield 'failed journal row target' => [1, 0, true];
        yield 'failed slot one' => [1, 1, true];
    }

    /**
     * @param int $slot Configuration being replaced
     * @param int $rowIndex Connection with an application level
     * @param bool $failed Whether the application level has failed
     */
    #[DataProvider('reconfigurationTargets')]
    public function testReconfigureRefusesBeforeChangingAnApplicationTarget(int $slot, int $rowIndex, bool $failed): void
    {
        $this->configureArchive();
        Database::configure(index: 1, database: 'main-change-log');
        Database::configure(index: 3, database: 'another_schema');
        Database::registerDatabase('shared_schema', 3, 2);
        Database::registerDatabase('slot_one_schema', 1, 2);
        $before = Database::getConnectionConfig($slot);
        Database::transactionStart($rowIndex);
        if ($failed) {
            new ReflectionProperty(Database::class, 'transactions')->getValue()[$rowIndex][0]->markFailed();
        }
        try {
            Database::configure(index: $slot, database: 'replacement');
            self::fail('Reconfiguration must refuse before replacing the configuration or aliases');
        } catch (NamedDatabaseException) {
            self::assertSame($before, Database::getConnectionConfig($slot));
            self::assertSame(2, Database::getDatabase('archive')->connectionIndex);
            self::assertSame(2, Database::getDatabase('shared_schema')->connectionIndex);
            self::assertSame(2, Database::getDatabase('slot_one_schema')->connectionIndex);
        } finally {
            Database::transactionRollback($rowIndex);
        }
    }

    /** @return iterable<string, array{int, bool}> Independent rival and killer slots, new or previously configured */
    public static function independentConfigurations(): iterable
    {
        yield 'new rival slot one' => [1, false];
        yield 'existing nonjournal slot one' => [1, true];
        yield 'new killer slot two' => [2, false];
        yield 'existing killer slot two' => [2, true];
    }

    /**
     * @param int $slot Independent low-level connection slot
     * @param bool $configured Whether a nonjournal configuration already exists in that slot
     */
    #[DataProvider('independentConfigurations')]
    public function testUnrelatedConfigurationIsAllowedUnderAnApplicationTransaction(int $slot, bool $configured): void
    {
        Database::configure(database: 'main');
        if ($configured) {
            Database::configure(index: $slot, database: 'main');
        }
        $primary = Database::getConnectionConfig(0);
        Database::transactionStart();
        try {
            Database::configure(index: $slot, database: 'main');
            self::assertSame('main', Database::getConnectionConfig($slot)->database);
            self::assertSame($primary, Database::getConnectionConfig(0));
            self::assertTrue(Database::hasOpenTransactions());
            self::assertSame(0, Database::getCurrentIndex());
        } finally {
            Database::transactionRollback();
        }
    }

    /** @param string $state Application level state on the independent slot */
    #[DataProvider('applicationStates')]
    public function testActiveNonjournalSlotOneCannotBeReconfigured(string $state): void
    {
        Database::configure(database: 'main');
        Database::configure(index: 1, database: 'main');
        $before = Database::getConnectionConfig(1);
        Database::transactionStart(1);
        $level = new ReflectionProperty(Database::class, 'transactions')->getValue()[1][0];
        if ($state === 'opened') {
            $level->markOpened();
        } elseif ($state === 'failed') {
            $level->markFailed();
        }
        try {
            Database::configure(index: 1, database: 'replacement');
            self::fail('An active independent target must refuse reconfiguration too');
        } catch (NamedDatabaseException) {
            self::assertSame($before, Database::getConnectionConfig(1));
        } finally {
            $level->markFailed();
            Database::transactionRollback(1);
        }
    }

    /** Only a new primary configuration resets the process-local transaction default. */
    public function testPrimaryConfigurationResetsScopeAndSecondaryConfigurationPreservesIt(): void
    {
        $this->configureArchive();
        new ReflectionProperty(Database::class, 'transactionIndex')->setValue(null, 2);
        Database::configure(index: 3, database: 'another');
        self::assertSame(2, new ReflectionProperty(Database::class, 'transactionIndex')->getValue());
        Database::configure(database: 'replacement');
        self::assertNull(new ReflectionProperty(Database::class, 'transactionIndex')->getValue());
    }

    /** @return iterable<string, array{string, bool}> Low-level ends and whether they commit */
    public static function scopedTransactionEnds(): iterable
    {
        yield 'commit' => ['transactionCommit', true];
        yield 'rollback' => ['transactionRollback', false];
        yield 'silent rollback' => ['transactionRollbackSilent', false];
    }

    /**
     * @param string $method Legacy low-level end called without an index
     * @param bool $commits Whether held work should be released
     */
    #[DataProvider('scopedTransactionEnds')]
    public function testLowLevelEndsInsideTheCallbackUseTheNamedScope(string $method, bool $commits): void
    {
        $this->configureArchive();
        Database::transactionStart(0);
        $made = false;
        $undone = false;
        $operation = new RuntimeException('callback ended its level');
        try {
            Database::transaction('archive', static function () use ($method, &$made, &$undone, $operation): void {
                Database::afterCommit(static function () use (&$made): void { $made = true; });
                Database::onRollback(static function () use (&$undone): void { $undone = true; });
                Database::$method();
                throw $operation;
            });
            self::fail('The callback must raise its failure');
        } catch (RuntimeException $failure) {
            self::assertSame($operation, $failure);
        }
        self::assertSame($commits, $made);
        self::assertSame(!$commits, $undone);
        self::assertCount(1, new ReflectionProperty(Database::class, 'transactions')->getValue()[0]);
        Database::transactionRollback();
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** Both legacy starts must see the named callback's ordinary non-nestable level. */
    public function testBothLowLevelStartsUseTheNamedScopeAndCannotStartOnAmbientInstead(): void
    {
        $this->configureArchive();
        Database::transaction('archive', static function (): void {
            foreach (['transactionStart', 'transactionStartNestable'] as $method) {
                try {
                    Database::$method();
                    self::fail('The ordinary named level refuses nesting');
                } catch (NestedTransactionRefusedException $failure) {
                    self::assertStringContainsString('connection 2', $failure->getMessage());
                }
            }
        });
        self::assertFalse(Database::hasOpenTransactions());
    }

    private function configureArchive(): void
    {
        Database::configure(database: 'main');
        Database::configure(index: 2, database: 'archive');
        Database::registerDatabase('archive', 2);
    }
}

class NamedDefaultEntity extends Entity
{
    public const string _table = 'named_rows';
}

class NamedArchiveEntity extends NamedDefaultEntity
{
    public const string _database = 'archive';
}

class NamedEmptyEntity extends NamedDefaultEntity
{
    public const string _database = '';
}

class NamedUnknownEntity extends NamedDefaultEntity
{
    public const string _database = 'unknown';
}

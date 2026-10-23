<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\OutboundRtSyncSubscriber;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\SyncSignalDataKey;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions as CollectionDbActions;
use Hilos\Database\Actions\Item\DbActions as ItemDbActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Filter\ColumnFilter;
use Hilos\Database\Filter\FilterOperator;
use Hilos\Database\Object\FilteredCollection;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\CrossConnectionWriteException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionLeftOpenException;
use Hilos\Database\PhpType;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Exception\UnplacedJournalColumnException;
use Hilos\Database\Schema\JournalCoverageGuard;
use Hilos\Database\Schema\EntityDatabaseStartupGuard;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\EntitySchemaAxis;
use Hilos\Database\Schema\Schema;
use Hilos\Database\SqlSortDirection;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions as CollectionRtActions;
use Hilos\Runtime\View\Actions\Item\RtActions as ItemRtActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;

/** Entity and collection routing against two real servers, including identical schema and table names. */
final class NamedDatabaseIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TARGETS = [
        [NamedPrimaryEntity::class, 'primary', 3, 0, 0, 1, 100],
        [NamedLocalEntity::class, 'local', 4, 0, 1, 2, 200],
        [NamedRemoteEntity::class, 'remote', 5, 2, 2, 1, 300],
    ];

    /** @var list<array{int, int}> Schemas and row connections whose fixture DDL this case owns */
    private array $createdTargets = [];

    private bool $createdSetParent = false;

    private ?HilosDbContext $previousDb = null;
    private ?RtContext $previousRt = null;
    private ?SignalRouter $previousRouter = null;
    private NamedFixtureReaction $reaction;
    private NamedFixtureMirror $mirror;

    /** Prepares three real row targets and local source observers inside the case handler. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->reaction = new NamedFixtureReaction();
        $this->mirror = new NamedFixtureMirror();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe($this->reaction);
        SourceChangeBus::subscribe($this->mirror);
        SourceChangeBus::subscribe(new OutboundRtSyncSubscriber());
        foreach (['primary', 'local', 'remote'] as $key) {
            TruthSourceRegistry::register($key, TruthSourceKeys::all(), 'named-fixture');
        }
        $primary = Database::getConnectionConfig(0);
        Database::configure(
            index: 1,
            host: $primary->host,
            user: $primary->user,
            password: $primary->password,
            database: ChangeLogDatabase::configuredName(),
            port: $primary->port,
            charset: $primary->charset,
            socket: $primary->socket,
        );
        Database::configure(
            index: 2,
            host: self::remoteEnvironment('HILOS_TEST_REMOTE_DB_HOST'),
            user: self::remoteEnvironment('HILOS_TEST_REMOTE_DB_USERNAME'),
            password: self::remoteEnvironment('HILOS_TEST_REMOTE_DB_PASSWORD'),
            database: $primary->database,
            port: (int) self::remoteEnvironment('HILOS_TEST_REMOTE_DB_PORT'),
            charset: $primary->charset,
        );
        Database::registerDatabase(NamedRemoteEntity::_database, 2);
        Database::connect(1);
        Database::connect(2);

        self::assertNotSame($primary->host, Database::getConnectionConfig(2)->host);
        self::assertNotSame($primary->user, Database::getConnectionConfig(2)->user);
        self::assertSame($primary->database, Database::getConnectionConfig(2)->database);
        foreach (self::TARGETS as [, $prefix, $count, $rowIndex, $schemaIndex, , $nextId]) {
            $table = self::fixtureTable($schemaIndex);
            Database::sql("DROP TABLE IF EXISTS {$table}", index: $rowIndex);
            Database::sql(
                "CREATE TABLE {$table} (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,"
                . " `label` VARCHAR(80) NOT NULL, `position` INT NOT NULL) ENGINE=InnoDB AUTO_INCREMENT={$nextId}",
                index: $rowIndex,
            );
            $this->createdTargets[] = [$schemaIndex, $rowIndex];
            Schema::reset($schemaIndex);
            for ($id = 1; $id <= $count; ++$id) {
                Database::sql(
                    "INSERT INTO {$table} (`id`, `label`, `position`) VALUES (?, ?, ?)",
                    [$id, "{$prefix}-{$id}", $id * 10],
                    index: $rowIndex,
                );
            }
        }
    }

    /** Ends transactions before fixture DDL and restores the facade's DB, RT and router. */
    protected function tearDown(): void
    {
        // End failed cases before DDL can implicitly commit a leaked application transaction.
        Database::handlerEnd();
        try {
            foreach ($this->createdTargets as [$schemaIndex, $rowIndex]) {
                if (!Database::isConnected($rowIndex)) {
                    Database::connect($rowIndex);
                }
                Database::sql('DROP TABLE IF EXISTS ' . self::fixtureTable($schemaIndex), index: $rowIndex);
                Schema::reset($schemaIndex);
            }
            if ($this->createdSetParent) {
                Database::sql('DROP TABLE IF EXISTS ' . NamedSetParentEntity::getSqlTableName(), index: 2);
                Schema::reset(2);
            }
        } finally {
            ExecutionContext::setCurrentAgentId(null);
            TruthSourceRegistry::unregisterAgent('named-set-fixture');
            SourceChangeBus::reset();
            TruthSourceRegistry::unregisterAgent('named-fixture');
            RtTruthSourceRegistry::unregisterAgent('named-fixture');
            Hilos::$db = $this->previousDb;
            Hilos::$rt = $this->previousRt;
            Hilos::$sr = $this->previousRouter;
            parent::tearDown();
        }
    }

    /** @return iterable<string, array{class-string<Entity>, int, int}> Secondary physical targets */
    public static function schemaTargets(): iterable
    {
        yield 'local schema on primary row connection' => [NamedLocalEntity::class, 1, 0];
        yield 'remote server with primary database name' => [NamedRemoteEntity::class, 2, 2];
    }

    /** @param class-string<Entity> $entityClass Entity whose secondary schema drifts */
    #[DataProvider('schemaTargets')]
    public function testAuditAndStartupFindSecondaryDriftBehindACorrectPrimaryNamesake(
        string $entityClass, int $schemaIndex, int $rowIndex,
    ): void {
        $this->mountFixture();
        $classes = [NamedPrimaryEntity::class, NamedLocalEntity::class, NamedRemoteEntity::class];
        Database::useConnection(1);
        self::assertSame([], EntitySchemaAudit::audit($classes));
        EntityDatabaseStartupGuard::assertMountedSchemasMatch();
        self::assertSame(1, Database::getCurrentIndex());

        Database::sql('ALTER TABLE ' . self::fixtureTable($schemaIndex) . ' MODIFY position VARCHAR(80) NULL', index: $rowIndex);
        Schema::reset($schemaIndex);
        Database::useConnection(0);
        $mismatches = EntitySchemaAudit::audit($classes);
        self::assertSame([EntitySchemaAxis::PROPERTY_NULLABLE, EntitySchemaAxis::TYPE], array_column($mismatches, 'axis'));
        self::assertSame([$entityClass, $entityClass], array_column($mismatches, 'entityClass'));
        self::assertSame(['position', 'position'], array_column($mismatches, 'subject'));
        self::assertSame(0, Database::getCurrentIndex());
        self::assertSame([], EntitySchemaAudit::audit($classes, 0), 'An explicit index filters rather than moving Entities');
        self::assertEquals($mismatches, EntitySchemaAudit::audit($classes, $schemaIndex));

        try {
            EntityDatabaseStartupGuard::assertMountedSchemasMatch();
            self::fail('A secondary schema drift must refuse startup');
        } catch (DatabaseException $failure) {
            self::assertStringContainsString($entityClass::getDatabase()->getDatabaseName(), $failure->getMessage());
            self::assertStringContainsString("schema connection {$schemaIndex}", $failure->getMessage());
            self::assertStringContainsString('named_database_fixture.position', $failure->getMessage());
            self::assertSame(0, Database::getCurrentIndex());
        }
    }

    public function testPhysicalAliasesShareTheAuditFilterButNotThePrimaryNamesakeOnAnotherHost(): void
    {
        $remote = Database::getConnectionConfig(2);
        Database::configure(index: 3, host: $remote->host, user: $remote->user, password: $remote->password,
            database: $remote->database, port: $remote->port, charset: $remote->charset);
        Database::registerDatabase('named_remote_alias', 3, 2);
        Database::sql('ALTER TABLE ' . self::fixtureTable(2) . ' DROP COLUMN position', index: 2);
        Schema::reset(2);
        Schema::reset(3);
        Database::useConnection(1);

        $classes = [NamedPrimaryEntity::class, NamedRemoteEntity::class, NamedRemoteAliasEntity::class];
        self::assertSame([], EntitySchemaAudit::audit($classes, 0));
        $mismatches = EntitySchemaAudit::audit($classes, 2);
        self::assertSame([NamedRemoteEntity::class, NamedRemoteAliasEntity::class], array_column($mismatches, 'entityClass'));
        self::assertSame([EntitySchemaAxis::COLUMN_MISSING, EntitySchemaAxis::COLUMN_MISSING], array_column($mismatches, 'axis'));
        self::assertEquals($mismatches, EntitySchemaAudit::audit($classes, 3));
        self::assertSame(1, Database::getCurrentIndex());
    }

    public function testTableCoverageClaimsOnlyEntitiesInTheCheckedPhysicalDatabase(): void
    {
        Database::useConnection(1);
        foreach ([[0, NamedRemoteEntity::class], [2, NamedPrimaryEntity::class], [1, NamedPrimaryEntity::class]] as [$index, $wrong]) {
            $mismatches = EntitySchemaAudit::auditTableCoverage([$wrong], index: $index);
            self::assertContains(NamedPrimaryEntity::_table, array_column($mismatches, 'table'));
            $allowed = EntitySchemaAudit::auditTableCoverage([$wrong], [NamedPrimaryEntity::_table], $index);
            self::assertNotContains(NamedPrimaryEntity::_table, array_column($allowed, 'table'));
        }
        foreach ([[0, NamedPrimaryEntity::class], [1, NamedLocalEntity::class], [2, NamedRemoteEntity::class]] as [$index, $right]) {
            self::assertNotContains(
                NamedPrimaryEntity::_table,
                array_column(EntitySchemaAudit::auditTableCoverage([$right], index: $index), 'table'),
            );
        }
        self::assertSame(1, Database::getCurrentIndex());
    }

    /** @param class-string<Entity> $entityClass Required Entity whose real table is absent */
    #[DataProvider('schemaTargets')]
    public function testStartupRequiresAnExplicitSecondaryTable(string $entityClass, int $schemaIndex, int $rowIndex): void
    {
        $this->mountFixture();
        Database::sql('DROP TABLE ' . self::fixtureTable($schemaIndex), index: $rowIndex);
        Schema::reset($schemaIndex);
        Database::useConnection(0);
        $mismatches = EntitySchemaAudit::audit([$entityClass]);
        self::assertCount(1, $mismatches);
        self::assertSame(EntitySchemaAxis::TABLE, $mismatches[0]->axis);
        try {
            EntityDatabaseStartupGuard::assertMountedSchemasMatch();
            self::fail('An absent secondary table must not hide behind its primary namesake');
        } catch (DatabaseException $failure) {
            self::assertStringContainsString("schema connection {$schemaIndex}", $failure->getMessage());
            self::assertStringContainsString('named_database_fixture: expected <table exists>, got <missing>', $failure->getMessage());
            self::assertSame(0, Database::getCurrentIndex());
        }
    }

    public function testMountedClassResolutionDoesNotAcceptASubclassInAnotherPhysicalDatabase(): void
    {
        Hilos::$db = new NamedSchemaContext([NamedLocalObjects::class, NamedRemoteObjects::class]);
        Hilos::$db->configure();
        self::assertSame(NamedPrimaryEntity::class, EntitySchemaAudit::mountedClassOf(NamedPrimaryEntity::class));
        Hilos::$db = new NamedSchemaContext([NamedPrimaryExtensionRows::class]);
        Hilos::$db->configure();
        Database::sql('ALTER TABLE ' . self::fixtureTable(0) . ' ADD note VARCHAR(80) NULL', index: 0);
        Schema::reset(0);
        self::assertSame(NamedPrimaryExtensionEntity::class, EntitySchemaAudit::mountedClassOf(NamedPrimaryEntity::class));
        EntityDatabaseStartupGuard::assertMountedSchemasMatch();
        $this->addToAssertionCount(1);
    }

    public function testAuditAndStartupRestoreTheCallerWhenTheSecondarySchemaIsUnreadable(): void
    {
        $this->mountFixture();
        Database::close(2);
        $remote = Database::getConnectionConfig(2);
        Database::configure(index: 2, host: $remote->host, user: $remote->user, password: $remote->password,
            database: 'named_database_absent_schema', port: $remote->port, charset: $remote->charset);
        Database::useConnection(1);
        try {
            foreach (['audit', 'startup'] as $door) {
                try {
                    if ($door === 'audit') {
                        EntitySchemaAudit::audit([NamedRemoteEntity::class]);
                    } else {
                        EntityDatabaseStartupGuard::assertMountedSchemasMatch();
                    }
                    self::fail('An unreadable secondary schema must be refused');
                } catch (DatabaseException $failure) {
                    self::assertStringContainsString('named_database_absent_schema', $failure->getMessage());
                    self::assertStringContainsString('named_database_fixture', $failure->getMessage());
                    self::assertStringNotContainsString($remote->password, $failure->getMessage());
                    self::assertStringNotContainsString($remote->user, $failure->getMessage());
                    self::assertSame(1, Database::getCurrentIndex());
                }
            }
        } finally {
            Database::configure(index: 2, host: $remote->host, user: $remote->user, password: $remote->password,
                database: $remote->database, port: $remote->port, charset: $remote->charset);
        }
    }

    public function testStartupChecksTheSharedRowCredentialsBesidesTheAccessibleSchemaConnection(): void
    {
        $remote = Database::getConnectionConfig(2);
        Database::configure(index: 3, host: $remote->host, user: $remote->user, password: 'wrong-row-secret',
            database: $remote->database, port: $remote->port, charset: $remote->charset);
        Database::registerDatabase('named_shared_row', 2, 3);
        Hilos::$db = new NamedSchemaContext([NamedSharedRowObjects::class]);
        Hilos::$db->configure();
        Database::useConnection(1);
        self::assertSame([], EntitySchemaAudit::audit([NamedSharedRowEntity::class]));
        try {
            EntityDatabaseStartupGuard::assertMountedSchemasMatch();
            self::fail('Schema credentials cannot prove that the shared row connection can read the table');
        } catch (DatabaseException $failure) {
            self::assertStringContainsString('cannot read table through row connection 3', $failure->getMessage());
            self::assertStringContainsString('named_shared_row', $failure->getMessage());
            self::assertStringContainsString('named_database_fixture', $failure->getMessage());
            self::assertStringNotContainsString('wrong-row-secret', $failure->getMessage());
            self::assertStringNotContainsString($remote->user, $failure->getMessage());
            self::assertSame(1, Database::getCurrentIndex());
        }
    }

    /** Coverage reads schema 1 and the real remote schema; the primary namesake is deliberately different. */
    public function testJournalCoverageReadsEachEntitySchemaAndRestoresTheCaller(): void
    {
        Hilos::$db = new NamedCoverageContext();
        Hilos::$db->configure();
        Database::sql('ALTER TABLE ' . self::fixtureTable(0) . ' ADD primary_only INT NULL', index: 0);
        Database::useConnection(2);

        JournalCoverageGuard::assertMountedTablesPlaced();
        self::assertSame(2, Database::getCurrentIndex());

        Database::sql('ALTER TABLE ' . self::fixtureTable(2) . ' ADD remote_only INT NULL', index: 2);
        Database::useConnection(1);
        try {
            JournalCoverageGuard::assertMountedTablesPlaced();
            self::fail('An unclassified remote live column must be refused');
        } catch (UnplacedJournalColumnException $failure) {
            self::assertStringContainsString('named_database_fixture.remote_only', $failure->getMessage());
            self::assertStringNotContainsString('primary_only', $failure->getMessage());
            self::assertSame(1, Database::getCurrentIndex());
        }
    }

    public function testJournalCoverageDoesNotSkipAGapOnSchemaOneAtRowZero(): void
    {
        Hilos::$db = new NamedCoverageContext();
        Hilos::$db->configure();
        Database::sql('ALTER TABLE ' . self::fixtureTable(1) . ' ADD local_only INT NULL', index: 0);
        Database::useConnection(2);
        try {
            JournalCoverageGuard::assertMountedTablesPlaced();
            self::fail('A live column on schema 1 must be judged, even though rows use connection 0');
        } catch (UnplacedJournalColumnException $failure) {
            self::assertStringContainsString('named_database_fixture.local_only', $failure->getMessage());
            self::assertSame(2, Database::getCurrentIndex());
        }
    }

    public function testJournalCoverageSkipsAnAbsentOptionalTableOnItsOwnSchema(): void
    {
        Hilos::$db = new NamedCoverageContext();
        Hilos::$db->configure();
        Database::sql('DROP TABLE ' . self::fixtureTable(1), index: 0);
        Database::sql('DROP TABLE ' . self::fixtureTable(2), index: 2);
        Database::sql('ALTER TABLE ' . self::fixtureTable(0) . ' ADD primary_only INT NULL', index: 0);
        Database::useConnection(2);
        JournalCoverageGuard::assertMountedTablesPlaced();
        self::assertSame(2, Database::getCurrentIndex());
    }

    public function testJournalCoverageRestoresTheCallerWhenALiveSchemaCannotBeRead(): void
    {
        Hilos::$db = new NamedCoverageContext();
        Hilos::$db->configure();
        Database::close(2);
        $remote = Database::getConnectionConfig(2);
        Database::configure(index: 2, host: $remote->host, user: $remote->user, password: $remote->password,
            database: 'named_database_schema_that_does_not_exist', port: $remote->port, charset: $remote->charset);
        Database::useConnection(0);
        try {
            JournalCoverageGuard::assertMountedTablesPlaced();
            self::fail('An unreadable mounted schema must be refused');
        } catch (UnplacedJournalColumnException $failure) {
            self::assertStringContainsString('cannot read live tables', $failure->getMessage());
            self::assertSame(0, Database::getCurrentIndex());
        } finally {
            Database::configure(index: 2, host: $remote->host, user: $remote->user, password: $remote->password,
                database: $remote->database, port: $remote->port, charset: $remote->charset);
        }
    }

    /** @return iterable<string, array{class-string<NamedPrimaryEntity>, string, int, int, int, int, int}> Physical targets */
    public static function targets(): iterable
    {
        foreach (self::TARGETS as $target) {
            yield $target[1] => $target;
        }
    }

    /** @param class-string<NamedPrimaryEntity> $entityClass Entity mapped to the target */
    #[DataProvider('targets')]
    public function testReadsUseTheDeclaredTarget(
        string $entityClass, string $prefix, int $count, int $rowIndex, int $schemaIndex, int $ambient, int $nextId,
    ): void {
        Database::useConnection($ambient);
        Database::sql('SELECT ? AS distraction', ['foreign query'], index: $ambient)->rows();
        $first = $entityClass::getById(1);
        self::assertNotNull($first);
        self::assertSame("{$prefix}-1", $first->label);
        self::assertSame(1, $first->id);
        self::assertSame(10, $first->position);
        self::assertTrue($first->isRelated());
        self::assertNull($entityClass::getById($nextId));
        self::assertSame($count, $entityClass::count());
        self::assertSame(2, $entityClass::countUpTo(1));
        self::assertSame($count, $entityClass::countUpTo($count));
        self::assertSame(1, $entityClass::count(['label' => "{$prefix}-1"]));
        self::assertSame(2, $entityClass::count('`position` >= ?', [($count - 1) * 10]));
        self::assertSame(0, $entityClass::countUpTo(2, ['label' => 'absent']));
        self::assertSame(2, $entityClass::countUpTo(2, '`position` >= ?', [($count - 1) * 10]));
        self::assertSame("{$prefix}-2", $entityClass::get(['label' => "{$prefix}-2"])->get(2)?->label);
        $window = $entityClass::get(
            '`position` >= ?', [20], ['position' => SqlSortDirection::DESC], limit: 1, offset: 1,
        );
        self::assertSame([$count - 1], $window->keys());
        self::assertSame("{$prefix}-" . ($count - 1), $window->get($count - 1)?->label);
        self::assertSame(self::labels($prefix, $count), array_values($entityClass::getAll()->map(
            static fn (NamedPrimaryEntity $row): string => $row->label,
        )));
        self::assertSame(self::labels($prefix, $count), self::rawLabels($schemaIndex, $rowIndex));
        self::assertSame($ambient, Database::getCurrentIndex());
    }

    /** EntityCollection's existing loader and nullable get() keep the Entity's database. */
    public function testEntityCollectionLoadersUseTheirEntityTarget(): void
    {
        Database::useConnection(1);
        foreach ([NamedPrimaryEntities::class, NamedLocalEntities::class, NamedRemoteEntities::class] as $collectionClass) {
            $rows = $collectionClass::initFullDB();
            $entityClass = $collectionClass::ENTITY_CLASS;
            self::assertCount($entityClass::count(), $rows);
            self::assertSame($entityClass::getById(1)?->label, $rows->get(1)?->label);
            self::assertNull($rows->get(999));
        }
        self::assertSame(1, Database::getCurrentIndex());
    }

    /** @param class-string<NamedPrimaryEntity> $entityClass Entity mapped to the target */
    #[DataProvider('targets')]
    public function testInsertUsesTargetAutoIncrementAndAffectedRows(
        string $entityClass, string $prefix, int $count, int $rowIndex, int $schemaIndex, int $ambient, int $nextId,
    ): void {
        $before = self::allRawLabels();
        Database::useConnection($ambient);
        Database::sql('SELECT 1 WHERE 0', index: $ambient)->rows();
        $row = $entityClass::getEmpty();
        $row->label = 'inserted';
        $row->position = 90;
        self::assertTrue($row->save());
        self::assertSame($nextId, $row->id);
        self::assertTrue($row->isRelated());
        self::assertSame($nextId, Database::lastInsertId($rowIndex));
        self::assertSame(1, Database::affectedRows($rowIndex));
        self::assertSame(0, Database::lastInsertId($ambient));
        self::assertSame(0, Database::affectedRows($ambient));
        $before[$prefix][] = 'inserted';
        self::assertSame($before, self::allRawLabels());
        self::assertCount($count + 1, self::rawLabels($schemaIndex, $rowIndex));
        self::assertSame($ambient, Database::getCurrentIndex());
    }

    /** @param class-string<NamedPrimaryEntity> $entityClass Entity mapped to the target */
    #[DataProvider('targets')]
    public function testUpdatePreservesExplicitColumnSelection(
        string $entityClass, string $prefix, int $count, int $rowIndex, int $schemaIndex, int $ambient, int $nextId,
    ): void {
        $before = self::allRawLabels();
        $row = $entityClass::getById(1);
        self::assertNotNull($row);
        Database::useConnection($ambient);
        $row->label = 'updated';
        $row->position = 99;
        self::assertTrue($row->save(['label']));
        self::assertSame(1, Database::affectedRows($rowIndex));
        $before[$prefix][0] = 'updated';
        self::assertSame($before, self::allRawLabels());
        self::assertSame(10, (int) Database::sql(
            'SELECT `position` FROM ' . self::fixtureTable($schemaIndex) . ' WHERE `id` = 1', index: $rowIndex,
        )->field('position'));
        self::assertTrue($row->save());
        self::assertSame(99, (int) Database::sql(
            'SELECT `position` FROM ' . self::fixtureTable($schemaIndex) . ' WHERE `id` = 1', index: $rowIndex,
        )->field('position'));
        self::assertSame($ambient, Database::getCurrentIndex());
    }

    /** @param class-string<NamedPrimaryEntity> $entityClass Entity mapped to the target */
    #[DataProvider('targets')]
    public function testSaveDiffChangesOnlyTheTargetAndComparedColumns(
        string $entityClass, string $prefix, int $count, int $rowIndex, int $schemaIndex, int $ambient, int $nextId,
    ): void {
        $before = self::allRawLabels();
        $original = $entityClass::getById(1);
        $row = $entityClass::getById(1);
        self::assertNotNull($original);
        self::assertNotNull($row);
        Database::useConnection($ambient);
        $row->label = 'diff';
        self::assertTrue($row->saveDiff($original));
        self::assertSame(1, Database::affectedRows($rowIndex));
        $before[$prefix][0] = 'diff';
        self::assertSame($before, self::allRawLabels());
        self::assertSame(10, (int) Database::sql(
            'SELECT `position` FROM ' . self::fixtureTable($schemaIndex) . ' WHERE `id` = 1', index: $rowIndex,
        )->field('position'));
        $saved = $entityClass::getById(1);
        self::assertNotNull($saved);
        $cached = Database::getCachedResultSet($rowIndex);
        self::assertTrue($row->saveDiff($saved));
        self::assertSame($cached, Database::getCachedResultSet($rowIndex), 'An unchanged diff sends no SQL');
        self::assertSame($ambient, Database::getCurrentIndex());
    }

    /** @param class-string<NamedPrimaryEntity> $entityClass Entity mapped to the target */
    #[DataProvider('targets')]
    public function testDeleteChangesOnlyTheTarget(
        string $entityClass, string $prefix, int $count, int $rowIndex, int $schemaIndex, int $ambient, int $nextId,
    ): void {
        $before = self::allRawLabels();
        $row = $entityClass::getById(1);
        self::assertNotNull($row);
        Database::useConnection($ambient);
        $row->delete();
        self::assertSame(1, Database::affectedRows($rowIndex));
        self::assertFalse($row->isRelated());
        array_shift($before[$prefix]);
        self::assertSame($before, self::allRawLabels());
        self::assertCount($count - 1, self::rawLabels($schemaIndex, $rowIndex));
        self::assertSame($ambient, Database::getCurrentIndex());
    }

    /** @return iterable<string, array{string, string}> Application states and direct Entity write doors */
    public static function guardedWrites(): iterable
    {
        foreach (['lazy', 'opened', 'failed'] as $state) {
            foreach (['insert', 'update', 'diff', 'unchanged diff', 'delete', 'missing update', 'missing delete'] as $write) {
                yield "{$state}: {$write}" => [$state, $write];
            }
        }
    }

    /** Refusal precedes even opening a closed target connection, including no-match WHERE and no-op diff. */
    #[DataProvider('guardedWrites')]
    public function testForeignApplicationTransactionsRefuseBeforeSqlOrEntityMutation(string $state, string $write): void
    {
        $before = self::allRawLabels();
        $original = NamedRemoteEntity::getById(1);
        self::assertNotNull($original);
        $row = $write === 'insert' ? NamedRemoteEntity::getEmpty() : NamedRemoteEntity::getById(1);
        self::assertNotNull($row);
        if (str_starts_with($write, 'missing')) {
            $row->id = 999;
        }
        if ($write !== 'unchanged diff') {
            $row->label = 'refused draft';
        }
        $draft = $row->toArray();
        $related = $row->isRelated();
        $originalData = new ReflectionProperty(Entity::class, '_originalData');
        $saved = $originalData->getValue($row);
        Database::close(2);
        $targetSql = new ReflectionProperty(Database::class, 'lastSql')->getValue()[2];
        Database::useConnection(2);
        Database::transactionStart(0);
        try {
            if ($state !== 'lazy') {
                Database::sql('SELECT 1', index: 0)->rows();
            }
            if ($state === 'failed') {
                Database::close(0);
            }
            try {
                match ($write) {
                    'insert', 'update', 'missing update' => $row->save(),
                    'diff', 'unchanged diff' => $row->saveDiff($original),
                    'delete', 'missing delete' => $row->delete(),
                };
                self::fail('A foreign application level must refuse every Entity write door');
            } catch (CrossConnectionWriteException $failure) {
                self::assertSame(
                    'Cannot write on connection 2 while an application transaction remains open on connection 0',
                    $failure->getMessage(),
                );
            }
            self::assertFalse(Database::isConnected(2));
            self::assertSame($targetSql, new ReflectionProperty(Database::class, 'lastSql')->getValue()[2]);
            self::assertSame($draft, $row->toArray());
            self::assertSame($related, $row->isRelated());
            self::assertSame($saved, $originalData->getValue($row));
            self::assertSame(2, Database::getCurrentIndex());
        } finally {
            Database::transactionRollback(0);
        }
        if (!Database::isConnected(0)) {
            Database::connect(0);
        }
        Database::connect(2);
        self::assertSame($before, self::allRawLabels());
    }

    /** Both qualified schemas use connection 0 and therefore the same real commit or rollback. */
    public function testLocalNamedAndPrimaryRowsShareOneTransaction(): void
    {
        $before = self::allRawLabels();
        Database::useConnection(2);
        Database::transaction(DatabaseNames::CHANGE_LOG, static function (): void {
            NamedPrimaryEntity::getById(1)->delete();
            NamedLocalEntity::getById(1)->delete();
            self::assertSame('remote-1', NamedRemoteEntity::getById(1)?->label, 'Foreign reads remain allowed');
        });
        array_shift($before['primary']);
        array_shift($before['local']);
        self::assertSame($before, self::allRawLabels());
        $refusal = new RuntimeException('roll back both local schemas');
        try {
            Database::transaction(DatabaseNames::PRIMARY, static function () use ($refusal): void {
                NamedPrimaryEntity::getById(2)->delete();
                NamedLocalEntity::getById(2)->delete();
                throw $refusal;
            });
            self::fail('The callback must fail');
        } catch (RuntimeException $failure) {
            self::assertSame($refusal, $failure);
        }
        self::assertSame($before, self::allRawLabels());
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame(2, Database::getCurrentIndex());
    }

    public function testNamedRemoteTransactionCommitsAndRollsBackOnlyRemoteRows(): void
    {
        $before = self::allRawLabels();
        Database::useConnection(1);
        Database::transaction(NamedRemoteEntity::_database, static function (): void {
            NamedRemoteEntity::getById(1)->delete();
            self::assertSame('primary-1', NamedPrimaryEntity::getById(1)?->label);
        });
        array_shift($before['remote']);
        self::assertSame($before, self::allRawLabels());
        $refusal = new RuntimeException('roll back remote');
        try {
            Database::transaction(NamedRemoteEntity::_database, static function () use ($refusal): void {
                NamedRemoteEntity::getById(2)->delete();
                throw $refusal;
            });
            self::fail('The callback must fail');
        } catch (RuntimeException $failure) {
            self::assertSame($refusal, $failure);
        }
        self::assertSame($before, self::allRawLabels());
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame(1, Database::getCurrentIndex());
    }

    /** Technical scope regression: synthetic roots alone do not make either row connection a foreign writer. */
    public function testSyntheticRootsAloneAllowEntityWritesOnBothConnections(): void
    {
        $before = self::allRawLabels();
        $scope = Database::beginRollbackScopeForTest();
        try {
            NamedPrimaryEntity::getById(1)->delete();
            NamedRemoteEntity::getById(1)->delete();
            self::assertCount(2, self::rawLabels(0, 0));
            self::assertCount(4, self::rawLabels(2, 2));
        } finally {
            $scope->rollback();
        }
        self::assertSame($before, self::allRawLabels());
    }

    /** @return iterable<string, array{string, int, int}> Every strategy on each physical target */
    public static function collectionStrategies(): iterable
    {
        foreach (['primary' => 3, 'local' => 4, 'remote' => 5] as $key => $count) {
            foreach ([Objects::LAZY_STRATEGY_NONE, Objects::LAZY_STRATEGY_KEY,
                Objects::LAZY_STRATEGY_BATCH, Objects::LAZY_STRATEGY_FULL_ON_ACCESS] as $strategy) {
                yield "{$key}-{$strategy}" => [$key, $count, $strategy];
            }
        }
    }

    /**
     * @param string $key Mounted collection key
     * @param int $count Initial target row count
     * @param int $strategy Collection loading strategy
     */
    #[DataProvider('collectionStrategies')]
    public function testCollectionReadsFollowTheirEntity(string $key, int $count, int $strategy): void
    {
        $this->mountFixture($strategy);
        Database::useConnection($key === 'remote' ? 1 : 2);
        $view = Hilos::$db->{$key};
        $objects = $view->getObjectCollection();
        self::assertNotNull($objects);
        self::assertSame('named_database_fixture', $objects->getTableName());
        self::assertSame($key === 'remote' ? 2 : 0, $objects->getDatabase()?->connectionIndex);
        if ($strategy !== Objects::LAZY_STRATEGY_FULL_ON_ACCESS) {
            self::assertSame($count, count($view));
        }
        $first = $view[1];
        self::assertSame("{$key}-1", $first->label);
        self::assertSame($count, count($view));
        self::assertSame($first, $view[1]);
        self::assertSame($first->getObject(), $objects[1]);
        self::assertSame(2, $objects->countByColumn('position', 10, 20));
        self::assertSame([1, 2], iterator_to_array($objects->loadByColumn('position', 10, 20), false));
        self::assertSame("{$key}-2", $view->whereColumnIs('position', 20)->first()?->label);

        $numbered = $objects->queryPage(new TableQueryDTO(limit: 1, pageIndex: 1));
        self::assertSame($count, $numbered[TableConstants::RESULT_KEY_TOTAL_COUNT]);
        self::assertSame([2], array_keys($numbered[TableConstants::RESULT_KEY_OBJECTS]));
        self::assertSame("{$key}-2", $numbered[TableConstants::RESULT_KEY_OBJECTS][2]->label);
        $keyset = $objects->queryPage(new TableQueryDTO(limit: 1, anchor: new TableAnchorDTO(['id' => 1])));
        self::assertSame([2], array_keys($keyset[TableConstants::RESULT_KEY_OBJECTS]));
        self::assertSame(1, $keyset[TableConstants::RESULT_KEY_ROWS_BEFORE]);

        $objects->preloadAll();
        self::assertSame(range(1, $count), $objects->keys());
        self::assertSame($first, $view[1]);
        self::assertSame($first->getObject(), $objects[1]);
        $filtered = $objects->filter(new ColumnFilter('position', FilterOperator::EQUALS, 10));
        self::assertEquals($objects->getDatabase(), $filtered->getDatabase());
        self::assertSame([1], $filtered->keys());
        self::assertSame($first->getObject(), $filtered[1]);
        self::assertSame($count, count($objects));
        self::assertSame(2, $objects->countByColumn('position', 10, 20));
        self::assertSame($key === 'remote' ? 1 : 2, Database::getCurrentIndex());
    }

    /**
     * @param class-string<NamedPrimaryEntity> $entityClass Entity mapped to the target
     * @param string $prefix Target and sentinel prefix
     * @param int $count Initial row count
     * @param int $rowIndex Connection executing row SQL
     * @param int $schemaIndex Configuration naming the physical schema
     * @param int $ambient Connection deliberately selected by the caller
     * @param int $nextId Target's next auto-increment id
     */
    #[DataProvider('targets')]
    public function testCollectionActionsWriteOnlyTheirTarget(
        string $entityClass, string $prefix, int $count, int $rowIndex, int $schemaIndex, int $ambient, int $nextId,
    ): void {
        $this->mountFixture();
        $before = self::allRawLabels();
        Database::useConnection($ambient);
        $view = Hilos::$db->{$prefix};
        $first = $view[1];
        $first->actions->rename('renamed');
        self::assertSame('renamed', self::rawLabels($schemaIndex, $rowIndex)[0]);
        self::assertSame($first, $view[1]);
        self::assertSame('renamed', $first->label);
        $created = $view->actions->create('created', 99);
        self::assertSame($nextId, $created->id);
        self::assertSame($nextId, Database::lastInsertId($rowIndex));
        self::assertSame(1, Database::affectedRows($rowIndex));
        self::assertTrue($created->getObject()->isRelated());
        self::assertSame($created->getObject(), $view[$nextId]->getObject());
        self::assertSame($view[$nextId], $view[$nextId]);
        self::assertSame('created', self::rawLabels($schemaIndex, $rowIndex)[$count]);
        $created->actions->delete();
        self::assertSame(1, Database::affectedRows($rowIndex));
        self::assertArrayNotHasKey($nextId, $view);
        self::assertFalse($created->getObject()->isRelated());
        $view->actions->deleteAll();
        self::assertSame($count, Database::affectedRows($rowIndex));
        self::assertSame([], self::rawLabels($schemaIndex, $rowIndex));
        self::assertSame(0, count($view));
        foreach (self::allRawLabels() as $key => $labels) {
            if ($key !== $prefix) {
                self::assertSame($before[$key], $labels);
            }
        }
        self::assertSame($ambient, Database::getCurrentIndex());
    }

    /**
     * @param class-string<NamedPrimaryEntity> $entityClass Entity mapped to the target
     * @param string $prefix Target and sentinel prefix
     * @param int $count Initial row count
     * @param int $rowIndex Connection executing row SQL
     * @param int $schemaIndex Configuration naming the physical schema
     * @param int $ambient Connection deliberately selected by the caller
     * @param int $nextId Target's next auto-increment id
     */
    #[DataProvider('targets')]
    public function testObjectMembershipAndCompletenessRollbackUseTheRowConnection(
        string $entityClass, string $prefix, int $count, int $rowIndex, int $schemaIndex, int $ambient, int $nextId,
    ): void {
        $this->mountFixture();
        $view = Hilos::$db->{$prefix};
        $objects = $view->getObjectCollection();
        $first = $view[1];
        $second = $view[2];
        $before = self::allRawLabels();
        $this->drainFrames();
        Database::useConnection($ambient);
        Database::transactionStart($rowIndex);
        $first->actions->rename('rolled back');
        $created = $view->actions->create('rolled back insert', 99);
        $second->actions->delete();
        $objects->preloadAll();
        self::assertTrue($objects->isAllLoaded());
        self::assertSame([], $this->reaction->seen);
        self::assertCount(3, $this->mirror->seen);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        Database::transactionRollback($rowIndex);
        self::assertSame($before, self::allRawLabels());
        self::assertSame($first, $view[1]);
        self::assertSame("{$prefix}-1", $first->label);
        self::assertSame($second->getObject(), $objects[2]);
        self::assertSame("{$prefix}-2", $second->label);
        self::assertTrue($second->getObject()->isRelated());
        self::assertFalse($created->getObject()->isRelated());
        self::assertNull($created->getObject()->id);
        self::assertSame('rolled back insert', $created->label);
        self::assertFalse($objects->isAllLoaded());
        self::assertTrue($objects->allowLazyLoading);
        self::assertFalse(isset($objects[3]));
        self::assertFalse(isset($objects[$nextId]));
        self::assertSame([], $this->reaction->seen);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertSame($ambient, Database::getCurrentIndex());
    }

    /**
     * @param class-string<NamedPrimaryEntity> $entityClass Entity mapped to the target
     * @param string $prefix Target and sentinel prefix
     * @param int $count Initial row count
     * @param int $rowIndex Connection executing row SQL
     * @param int $schemaIndex Configuration naming the physical schema
     * @param int $ambient Connection deliberately selected by the caller
     * @param int $nextId Target's next auto-increment id
     */
    #[DataProvider('targets')]
    public function testBulkClearAndRehydrateRollbackRestoreTheHeldInstances(
        string $entityClass, string $prefix, int $count, int $rowIndex, int $schemaIndex, int $ambient, int $nextId,
    ): void {
        $this->mountFixture(Objects::LAZY_STRATEGY_NONE);
        $view = Hilos::$db->{$prefix};
        $objects = $view->getObjectCollection();
        $first = $view[1];
        $map = iterator_to_array($objects);
        $before = self::allRawLabels();
        Database::useConnection($ambient);
        Database::transactionStart($rowIndex);
        $view->actions->deleteAll();
        self::assertSame([], self::rawLabels($schemaIndex, $rowIndex));
        self::assertSame(0, count($objects));
        $objects->reHydrate();
        Database::transactionRollback($rowIndex);
        self::assertSame($before, self::allRawLabels());
        self::assertSame($map, iterator_to_array($objects));
        self::assertSame($first->getObject(), $view[1]->getObject());
        self::assertSame("{$prefix}-1", $first->label);
        self::assertTrue($objects->isAllLoaded());
        self::assertFalse($objects->allowLazyLoading);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @return iterable<string, array{string}> Cache-loading entrances */
    public static function cacheLoaders(): iterable
    {
        foreach (['key', 'column', 'query', 'preload', 'loadAll', 'rehydrate'] as $loader) {
            yield $loader => [$loader];
        }
    }

    /** @param string $loader Cache-loading entrance exercised under a foreign transaction */
    #[DataProvider('cacheLoaders')]
    public function testPrimaryRollbackDoesNotForgetForeignReads(string $loader): void
    {
        $this->mountFixture();
        $objects = Hilos::$db->remote->getObjectCollection();
        $first = Hilos::$db->remote[1];
        if ($loader === 'rehydrate') {
            $objects->preloadAll();
        }
        Database::transactionStart(0);
        match ($loader) {
            'key' => Hilos::$db->remote[2],
            'column' => iterator_to_array($objects->loadByColumn('position', 20, 30)),
            'query' => $objects->queryPage(new TableQueryDTO(limit: 3)),
            'preload' => $objects->preloadAll(),
            'loadAll' => $objects->loadAllFromDB(),
            'rehydrate' => $objects->reHydrate(),
        };
        $second = Hilos::$db->remote[2];
        $allLoaded = $objects->isAllLoaded();
        $allowLazy = $objects->allowLazyLoading;
        Database::transactionRollback(0);
        self::assertSame($second, Hilos::$db->remote[2]);
        self::assertSame($second->getObject(), $objects[2]);
        self::assertSame('remote-2', $second->label);
        self::assertSame($allLoaded, $objects->isAllLoaded());
        self::assertSame($allowLazy, $objects->allowLazyLoading);
        if ($loader !== 'loadAll' && $loader !== 'rehydrate') {
            self::assertSame($first, Hilos::$db->remote[1]);
        }
    }

    /** @return iterable<string, array{string, string}> Foreign application levels and mutation entrances */
    public static function foreignCollectionWrites(): iterable
    {
        foreach (['lazy', 'open', 'failed'] as $state) {
            foreach (['create', 'update', 'delete', 'deleteAll', 'writeGuard', 'setGuard', 'createGuard', 'createSetGuard'] as $door) {
                yield "{$state}-{$door}" => [$state, $door];
            }
        }
    }

    /**
     * @param string $state Foreign application's transaction state
     * @param string $door Mutation or early action guard under test
     */
    #[DataProvider('foreignCollectionWrites')]
    public function testForeignCollectionWritesRefuseBeforeSqlAndMemory(string $state, string $door): void
    {
        $this->mountFixture();
        $view = Hilos::$db->remote;
        $first = $view[1];
        $objects = $view->getObjectCollection();
        $before = self::allRawLabels();
        $this->drainFrames();
        $targetSql = new ReflectionProperty(Database::class, 'lastSql')->getValue()[2];
        Database::transactionStart(0);
        if ($state !== 'lazy') {
            Database::sql('SELECT 1', index: 0);
        }
        if ($state === 'failed') {
            Database::close(0);
        }
        try {
            match ($door) {
                'create' => $view->actions->create('refused', 99),
                'update' => $first->actions->rename('refused'),
                'delete' => $first->actions->delete(),
                'deleteAll' => $view->actions->deleteAll(),
                default => $view->actions->customSqlAfterGuard($door),
            };
            self::fail('A foreign application level must refuse the write');
        } catch (CrossConnectionWriteException) {
            self::assertSame('remote-1', $first->label);
            self::assertSame($first, $view[1]);
            self::assertSame($first->getObject(), $objects[1]);
            self::assertTrue($first->getObject()->isRelated());
            self::assertFalse($objects->isAllLoaded());
            self::assertSame([], $this->reaction->seen);
            self::assertSame([], $this->mirror->seen);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
            self::assertSame($targetSql, new ReflectionProperty(Database::class, 'lastSql')->getValue()[2]);
            self::assertSame($before['remote'], self::rawLabels(2, 2));
        } finally {
            Database::transactionRollback(0);
            if ($state === 'failed') {
                Database::connect(0);
            }
        }
        self::assertSame($before, self::allRawLabels());
    }

    /** @return iterable<string, array{string}> Independent doors that can traverse a cold set parent */
    public static function coldParentWrites(): iterable
    {
        foreach (['createInSet', 'updateSet', 'removeSet', 'updateItem', 'removeItem',
            'syncObject', 'insertObject', 'deleteObject'] as $door) {
            yield $door => [$door];
        }
    }

    /**
     * A lazy foreign level must refuse before the set-only claim reads or hydrates its parent.
     *
     * @param string $door Independent write entrypoint
     */
    #[DataProvider('coldParentWrites')]
    public function testForeignWriteRefusesBeforeColdSetParentTraversal(string $door): void
    {
        $this->mountSetFixture();
        $first = Hilos::$db->namedSetChildren[1];
        $entity = new ReflectionProperty(Object_::class, 'entity')->getValue($first->getObject());
        $insert = NamedSetChildObject::create();
        $insert->changeLabel('allowed');
        $insert->changePosition(10);
        $parents = Hilos::$db->getObjectCollection('namedSetParents');
        $before = self::allRawLabels();
        $lastSql = new ReflectionProperty(Database::class, 'lastSql')->getValue();
        self::assertSame([], $parents->toArray());
        self::assertSame(0, $parents->loads);
        Database::transactionStart(0);
        try {
            try {
                $this->writeWithSetClaim($door, $first, $insert);
                self::fail('A foreign lazy application level must refuse before reading the parent');
            } catch (CrossConnectionWriteException) {
                self::assertSame(0, $parents->loads, 'Ownership traversal must not start before the connection refusal');
                self::assertSame([], $parents->toArray());
                self::assertFalse($parents->isAllLoaded());
                self::assertSame($lastSql, new ReflectionProperty(Database::class, 'lastSql')->getValue());
                self::assertSame($entity, new ReflectionProperty(Object_::class, 'entity')->getValue($first->getObject()));
                self::assertSame('remote-1', $first->label);
                self::assertTrue($first->getObject()->isRelated());
                self::assertFalse($first->getObject()->hasChanges());
                self::assertSame($first, Hilos::$db->namedSetChildren[1]);
                self::assertSame([1], array_keys(Hilos::$db->getObjectCollection('namedSetChildren')->toArray()));
                self::assertNull($insert->id);
                self::assertFalse($insert->isRelated());
                self::assertSame('allowed', $insert->label);
                self::assertSame(10, $insert->position);
                self::assertSame([], $this->mirror->seen);
                self::assertSame([], $this->reaction->seen);
                self::assertNull(Hilos::$sr->getNextQueuedSignal());
                foreach (['1', '300'] as $id) {
                    self::assertFalse(Hilos::$sr->shouldSkipDbSyncApply('namedSetChildren', $id, Hilos::$sr->getEmitter()));
                }
                // Inspect independently only after checking lastSql and cold membership.
                self::assertSame($before, self::allRawLabels());
            }
        } finally {
            Database::transactionRollback(0);
        }
        $this->writeWithSetClaim($door, $first, $insert);
        self::assertSame(1, $parents->loads, 'The same matching claim must traverse the real parent after rollback');
        $this->assertSetWritePersisted($door);
    }

    /**
     * Without the foreign level, each door must really load the parent and accept the matching set.
     *
     * @param string $door Independent write entrypoint
     */
    #[DataProvider('coldParentWrites')]
    public function testMatchingSetOnlyClaimWritesThroughColdParent(string $door): void
    {
        $this->mountSetFixture();
        $first = Hilos::$db->namedSetChildren[1];
        $insert = NamedSetChildObject::create();
        $insert->changeLabel('allowed');
        $insert->changePosition(10);
        $parents = Hilos::$db->getObjectCollection('namedSetParents');
        self::assertSame([], $parents->toArray());
        self::assertSame(0, $parents->loads);
        $this->writeWithSetClaim($door, $first, $insert);
        self::assertSame(1, $parents->loads);
        self::assertSame([10], array_keys($parents->toArray()));
        self::assertSame(77, $parents[10]->position);
        $this->assertSetWritePersisted($door);
    }

    /** Whole-table guards also refuse the connection before judging the insufficient set-only grant. */
    public function testForeignConnectionPrecedesWholeTableActionRights(): void
    {
        $this->mountSetFixture();
        Database::transactionStart(0);
        try {
            foreach (['writeGuard', 'createGuard'] as $door) {
                try {
                    Hilos::$db->namedSetChildren->actions->wholeTableGuard($door);
                    self::fail('The foreign connection must refuse first');
                } catch (CrossConnectionWriteException) {
                    self::assertSame(0, Hilos::$db->getObjectCollection('namedSetParents')->loads);
                }
            }
        } finally {
            Database::transactionRollback(0);
        }
    }

    /** Wrong set and missing operations still fail ownership after reading the real parent. */
    public function testColdParentGuardsKeepSetAndOperationRestrictions(): void
    {
        $this->mountSetFixture();
        $first = Hilos::$db->namedSetChildren[1];
        $before = self::allRawLabels();
        TruthSourceRegistry::unregisterAgent('named-set-fixture');
        TruthSourceRegistry::register('namedSetChildren', TruthSourceKeys::set('88'), 'named-set-fixture');
        try {
            $first->actions->rename('denied');
            self::fail('A different set cannot edit the child');
        } catch (WriteNotAllowedException) {
            self::assertSame(1, Hilos::$db->getObjectCollection('namedSetParents')->loads);
        }
        try {
            Hilos::$db->namedSetChildren->actions->createInSet('denied', 10);
            self::fail('A different set cannot create a child');
        } catch (CreateNotAllowedException) {
            self::assertSame('remote-1', $first->label);
        }
        TruthSourceRegistry::unregisterAgent('named-set-fixture');
        TruthSourceRegistry::register('namedSetChildren', TruthSourceKeys::set('77'),
            'named-set-fixture', TruthSourceOperations::of(TruthSourceOperation::Add));
        try {
            Hilos::$db->namedSetChildren->actions->removeSet(10);
            self::fail('An add-only set grant cannot remove children');
        } catch (WriteNotAllowedException) {
            self::assertSame($before, self::allRawLabels());
            self::assertSame([], $this->mirror->seen);
            self::assertSame([], $this->reaction->seen);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    /** A refused Object insert cannot acquire an id, membership or announcements. */
    public function testFailedObjectInsertKeepsItsDraftWithoutIdOrAnnouncements(): void
    {
        $this->mountFixture();
        $object = NamedRemoteObject::create();
        $object->changeLabel('draft');
        $before = self::allRawLabels();
        Database::transactionStart(0);
        try {
            $object->sync();
            self::fail('The foreign insert must refuse');
        } catch (CrossConnectionWriteException) {
            self::assertNull($object->id);
            self::assertSame('draft', $object->label);
            self::assertFalse($object->isRelated());
            self::assertSame([], $this->reaction->seen);
            self::assertSame([], $this->mirror->seen);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        } finally {
            Database::transactionRollback(0);
        }
        self::assertSame($before, self::allRawLabels());
    }

    /** The named remote callback releases its own facts and frames while ambient points elsewhere. */
    public function testRemoteCommitReleasesObjectAndMembershipAnnouncementsOnItsOwnStack(): void
    {
        $this->mountFixture();
        $first = Hilos::$db->remote[1];
        Database::useConnection(1);
        Database::transaction('named_test_remote', function () use ($first): void {
            $first->actions->rename('committed');
            $created = Hilos::$db->remote->actions->create('committed insert', 99);
            self::assertSame(300, $created->id);
            self::assertCount(2, $this->mirror->seen);
            self::assertSame([], $this->reaction->seen);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        });
        self::assertSame('committed', self::rawLabels(2, 2)[0]);
        self::assertSame('committed insert', self::rawLabels(2, 2)[5]);
        self::assertSame($first, Hilos::$db->remote[1]);
        self::assertCount(2, $this->reaction->seen);
        self::assertCount(2, $this->drainFrames());
        self::assertSame(1, Database::getCurrentIndex());
        Database::transactionStart(0);
        Database::transactionRollback(0);
        self::assertSame('committed', $first->label);
    }

    /** Synthetic roots alone permit both writers; a primary application child closes the remote door. */
    public function testSyntheticRootsAllowCollectionsUntilAnApplicationChildOpens(): void
    {
        $this->mountFixture();
        $before = self::allRawLabels();
        $scope = Database::beginRollbackScopeForTest();
        try {
            Hilos::$db->primary[1]->actions->rename('root primary');
            Hilos::$db->remote[1]->actions->rename('root remote');
            self::assertSame('root remote', self::rawLabels(2, 2)[0]);
            Database::transactionStart(0);
            try {
                Hilos::$db->remote->actions->deleteAll();
                self::fail('The application child must refuse a foreign collection write');
            } catch (CrossConnectionWriteException) {
                self::assertCount(5, self::rawLabels(2, 2));
            } finally {
                Database::transactionRollback(0);
            }
        } finally {
            $scope->rollback();
        }
        self::assertSame($before, self::allRawLabels());
        self::assertSame('primary-1', Hilos::$db->primary[1]->label);
        self::assertSame('remote-1', Hilos::$db->remote[1]->label);
        self::assertSame([], $this->reaction->seen);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** A derived local schema shares both SQL rollback and Object undo with primary. */
    public function testPrimaryAndLocalObjectsRollbackTogetherOnTheirSharedRowConnection(): void
    {
        $this->mountFixture();
        $primary = Hilos::$db->primary[1];
        $local = Hilos::$db->local[1];
        $before = self::allRawLabels();
        Database::useConnection(2);
        Database::transactionStart(0);
        $primary->actions->rename('primary edit');
        $local->actions->rename('local edit');
        $primaryCreated = Hilos::$db->primary->actions->create('primary insert', 99);
        $localCreated = Hilos::$db->local->actions->create('local insert', 99);
        self::assertSame('primary edit', self::rawLabels(0, 0)[0]);
        self::assertSame('local edit', self::rawLabels(1, 0)[0]);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertSame([], $this->reaction->seen);
        Database::transactionRollback(0);

        self::assertSame($before, self::allRawLabels());
        self::assertSame($primary, Hilos::$db->primary[1]);
        self::assertSame($local, Hilos::$db->local[1]);
        self::assertSame('primary-1', $primary->label);
        self::assertSame('local-1', $local->label);
        self::assertNull($primaryCreated->getObject()->id);
        self::assertNull($localCreated->getObject()->id);
        self::assertArrayNotHasKey(100, Hilos::$db->primary);
        self::assertArrayNotHasKey(200, Hilos::$db->local);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertSame([], $this->reaction->seen);
    }

    /** A failed named callback restores the remote cache and leaves primary untouched. */
    public function testNamedRemoteCallbackRollsBackObjectAndCollectionMemory(): void
    {
        $this->mountFixture();
        $first = Hilos::$db->remote[1];
        $objects = Hilos::$db->remote->getObjectCollection();
        $before = self::allRawLabels();
        $failure = new RuntimeException('cancel named object work');
        Database::useConnection(1);
        try {
            Database::transaction('named_test_remote', function () use ($first, $objects, $failure): void {
                $first->actions->rename('canceled');
                Hilos::$db->remote->actions->create('canceled insert', 99);
                $objects->preloadAll();
                self::assertTrue($objects->isAllLoaded());
                self::assertNull(Hilos::$sr->getNextQueuedSignal());
                throw $failure;
            });
            self::fail('The callback must fail');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame($before, self::allRawLabels());
        self::assertSame($first, Hilos::$db->remote[1]);
        self::assertSame('remote-1', $first->label);
        self::assertFalse($objects->isAllLoaded());
        self::assertFalse(isset($objects[2]));
        self::assertFalse(isset($objects[300]));
        self::assertSame([], $this->reaction->seen);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame(1, Database::getCurrentIndex());
    }

    /** @return iterable<string, array{bool}> Real commit and callback rollback */
    public static function transactionOutcomes(): iterable
    {
        yield 'commit' => [true];
        yield 'callback rollback' => [false];
    }

    /**
     * DB, Object, View and RT share the remote transaction despite another ambient connection.
     *
     * @param bool $commit Whether the callback succeeds
     */
    #[DataProvider('transactionOutcomes')]
    public function testRemoteTransactionOwnsDbAndRuntimeMemoryAndAnnouncements(bool $commit): void
    {
        $this->mountFixture();
        $this->mountRuntimeFixture();
        $primary = Hilos::$db->primary[1];
        $first = Hilos::$db->remote[1];
        $removed = Hilos::$db->remote[2]->getObject();
        $kept = Hilos::$rt->namedRuntime['kept'];
        $dropped = Hilos::$rt->namedRuntime['dropped'];
        $objects = Hilos::$db->remote->getObjectCollection();
        $before = self::allRawLabels();
        $created = null;
        $failure = new RuntimeException('cancel remote DB and RT');
        Database::useConnection(1);
        try {
            Database::transaction('named_test_remote', function () use ($commit, $failure, &$created): void {
                $created = $this->mutateRemoteAndRuntime();
                self::assertSame('changed', Hilos::$db->remote[1]->label);
                self::assertSame('changed', Hilos::$rt->namedRuntime['kept']->label());
                self::assertArrayNotHasKey(2, Hilos::$db->remote);
                self::assertNull(Hilos::$rt->namedRuntime['dropped']);
                Hilos::$db->remote->getObjectCollection()->preloadAll();
                self::assertTrue(Hilos::$db->remote->getObjectCollection()->isAllLoaded());
                self::assertCount(5, $this->mirror->seen);
                $this->assertNoReleasedAnnouncements();
                self::assertSame(1, Database::getCurrentIndex());
                if (!$commit) {
                    throw $failure;
                }
            });
            self::assertTrue($commit);
        } catch (RuntimeException $caught) {
            self::assertFalse($commit);
            self::assertSame($failure, $caught);
        }
        self::assertNotNull($created);
        self::assertSame($primary, Hilos::$db->primary[1]);
        self::assertSame($before['primary'], self::rawLabels(0, 0));
        self::assertSame($before['local'], self::rawLabels(1, 0));
        self::assertSame($first, Hilos::$db->remote[1]);
        self::assertSame(1, Database::getCurrentIndex());
        self::assertFalse(Database::hasOpenTransactions());
        if ($commit) {
            self::assertSame(['changed', 'remote-3', 'remote-4', 'remote-5', 'added'], self::rawLabels(2, 2));
            self::assertTrue($objects->isAllLoaded());
            self::assertSame($created->getObject(), Hilos::$db->remote[300]->getObject());
            self::assertSame('changed', $kept->label());
            self::assertSame('added', Hilos::$rt->namedRuntime['added']->label());
            self::assertNull(Hilos::$rt->namedRuntime['dropped']);
            $this->assertRemoteAnnouncementsReleased();
        } else {
            self::assertSame($before['remote'], self::rawLabels(2, 2));
            self::assertSame('remote-1', $first->label);
            self::assertSame($removed, Hilos::$db->remote[2]->getObject());
            self::assertFalse($objects->isAllLoaded());
            self::assertNull($created->getObject()->id);
            self::assertFalse(isset($objects[300]));
            foreach ([3, 4, 5] as $id) {
                self::assertFalse(isset($objects[$id]), 'Rows read only inside the transaction must be forgotten');
            }
            self::assertTrue($objects->allowLazyLoading);
            self::assertSame('before', $kept->label());
            self::assertSame('dropped', $dropped->label());
            self::assertSame('dropped', Hilos::$rt->namedRuntime['dropped']->label());
            self::assertNull(Hilos::$rt->namedRuntime['added']);
            self::assertCount(2, Hilos::$rt->namedRuntime);
            $this->assertRemoteRollbackMirrors();
            $this->assertNoReleasedAnnouncements();
            // A foreign change after rollback must not be consumed as a phantom self echo.
            DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData('remote', '1', ['label' => 'foreign']));
            RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData('namedRuntime', 'kept', ['label' => 'foreign']));
            RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData('namedRuntime', 'dropped', ['label' => 'foreign']));
            self::assertSame('foreign', $first->label);
            self::assertSame('foreign', $kept->label());
            self::assertSame('foreign', $dropped->label(), 'Undo must restore the same removed state instance');
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
        $this->assertAmbientDefaultRestored(1);
    }

    /** Local named rows, primary rows and default RT hooks commit on connection zero. */
    public function testLocalNamedCommitReleasesSharedRowsAndRuntimeInWriteOrder(): void
    {
        $this->mountFixture();
        $this->mountRuntimeFixture();
        $primary = Hilos::$db->primary[1];
        $local = Hilos::$db->local[1];
        $remote = Hilos::$db->remote[1];
        Database::useConnection(2);
        $explicitReleased = false;
        Database::transaction(DatabaseNames::CHANGE_LOG, function () use (&$explicitReleased): void {
            Hilos::$db->primary[1]->actions->rename('primary committed');
            Hilos::$db->local[1]->actions->rename('local committed');
            Hilos::$rt->namedRuntime->actions->create('added', 'local runtime');
            Database::afterCommit(static function () use (&$explicitReleased): void { $explicitReleased = true; }, 0);
            self::assertFalse($explicitReleased);
            self::assertSame(['primary', 'local', 'namedRuntime'], array_column($this->mirror->seen, 'sourceKey'));
            self::assertSame([], $this->reaction->seen);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        });
        self::assertTrue($explicitReleased);
        self::assertSame('primary committed', self::rawLabels(0, 0)[0]);
        self::assertSame('local committed', self::rawLabels(1, 0)[0]);
        self::assertSame($primary, Hilos::$db->primary[1]);
        self::assertSame($local, Hilos::$db->local[1]);
        self::assertSame($remote, Hilos::$db->remote[1]);
        self::assertSame('remote-1', $remote->label);
        self::assertSame('local runtime', Hilos::$rt->namedRuntime['added']->label());
        self::assertSame(['primary', 'local', 'namedRuntime'], array_column($this->reaction->seen, 'sourceKey'));
        self::assertSame(
            [SignalConstants::DB_SYNC_UPDATED, SignalConstants::DB_SYNC_UPDATED, SignalConstants::RT_SYNC_CREATED],
            $this->drainFrames(),
        );
        $this->assertAmbientDefaultRestored(2);
    }

    /** An explicit primary hook stays independent of the named remote default on a real server. */
    public function testExplicitPrimaryHooksOutliveTheRemoteNamedCommit(): void
    {
        $this->mountFixture();
        $this->mountRuntimeFixture();
        $released = [];
        $undone = [];
        Database::transactionStart(0);
        Database::transaction('named_test_remote', function () use (&$released, &$undone): void {
            Hilos::$rt->namedRuntime->actions->create('added', 'remote runtime');
            Database::afterCommit(static function () use (&$released): void { $released[] = 'primary'; }, 0);
            Database::onRollback(static function () use (&$undone): void { $undone[] = 'primary'; }, 0);
            Database::afterCommit(static function () use (&$released): void { $released[] = 'remote'; });
            Database::onRollback(static function () use (&$undone): void { $undone[] = 'remote'; });
            Database::sql('SELECT CONNECTION_ID()', index: 2);
            self::assertSame([], $released);
        });
        self::assertSame(['remote'], $released);
        self::assertSame([SignalConstants::RT_SYNC_CREATED], $this->drainFrames());
        Database::transactionRollback(0);
        self::assertSame(['primary'], $undone);
        self::assertSame('remote runtime', Hilos::$rt->namedRuntime['added']->label());
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** @return iterable<string, array{bool}> Driver COMMIT failure or driver ROLLBACK failure */
    public static function killedRemoteOutcomes(): iterable
    {
        yield 'COMMIT fails' => [false];
        yield 'ROLLBACK fails after callback error' => [true];
    }

    /**
     * Both failures occur on the actual second server after ORM and runtime writes.
     *
     * @param bool $callbackFails Whether a callback error precedes the failed cleanup
     */
    #[DataProvider('killedRemoteOutcomes')]
    public function testKilledRemoteSessionRestoresMemoryAndPreservesTheOriginalFailure(bool $callbackFails): void
    {
        $this->mountFixture();
        $this->mountRuntimeFixture();
        $primarySession = Database::sql('SELECT CONNECTION_ID() AS id', index: 0)->field('id');
        $primary = Hilos::$db->primary[1];
        $first = Hilos::$db->remote[1];
        $removed = Hilos::$db->remote[2]->getObject();
        $kept = Hilos::$rt->namedRuntime['kept'];
        $objects = Hilos::$db->remote->getObjectCollection();
        $before = self::allRawLabels();
        $failure = new RuntimeException('original remote callback failure');
        $created = null;
        $killed = false;
        $logFile = tempnam(sys_get_temp_dir(), 'hilos-remote-failure-');
        self::assertNotFalse($logFile);
        Logger::setLogFile($logFile);
        Database::useConnection(1);
        try {
            try {
                Database::transaction('named_test_remote', function () use ($callbackFails, $failure, &$created, &$killed): void {
                    $created = $this->mutateRemoteAndRuntime();
                    Hilos::$db->remote->getObjectCollection()->preloadAll();
                    $this->assertNoReleasedAnnouncements();
                    $this->killRemoteSession();
                    $killed = true;
                    if ($callbackFails) {
                        throw $failure;
                    }
                });
                self::fail('The killed remote session must fail');
            } catch (DatabaseRuntimeException $caught) {
                self::assertFalse($callbackFails);
                self::assertStringContainsString('Query: COMMIT', $caught->getMessage());
            } catch (RuntimeException $caught) {
                self::assertTrue($callbackFails);
                self::assertSame($failure, $caught);
            }
            self::assertTrue($killed, 'The failure must follow an actual KILL on remote2');
            if ($callbackFails) {
                $log = (string) file_get_contents($logFile);
                self::assertStringContainsString('A silent transaction rollback failed: ', $log);
                self::assertStringContainsString('Query: ROLLBACK', $log);
            }
        } finally {
            Logger::resetLogFile();
            unlink($logFile);
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], Database::handlerEnd());
        self::assertSame(1, Database::getCurrentIndex());
        self::assertNotNull($created);
        self::assertNull($created->getObject()->id);
        self::assertFalse($objects->isAllLoaded());
        self::assertSame($first, Hilos::$db->remote[1]);
        self::assertSame('remote-1', $first->label);
        self::assertSame($removed, Hilos::$db->remote[2]->getObject());
        self::assertSame('before', $kept->label());
        self::assertSame('dropped', Hilos::$rt->namedRuntime['dropped']->label());
        self::assertNull(Hilos::$rt->namedRuntime['added']);
        $this->assertRemoteRollbackMirrors();
        $this->assertNoReleasedAnnouncements();
        Database::close(2);
        Database::connect(2);
        self::assertSame($before, self::allRawLabels());
        self::assertSame($primary, Hilos::$db->primary[1]);
        self::assertSame($primarySession, Database::sql('SELECT CONNECTION_ID() AS id', index: 0)->field('id'));
        $this->assertAmbientDefaultRestored(1);
    }

    /** @return iterable<string, array{bool}> Delivery inside and outside a handler */
    public static function announcementHandlers(): iterable
    {
        yield 'handler defers the failure' => [true];
        yield 'caller receives the failure' => [false];
    }

    /**
     * A failing reaction cannot undo the real remote commit or stop later frame callbacks.
     *
     * @param bool $inHandler Whether a handler holds the announcement failure
     */
    #[DataProvider('announcementHandlers')]
    public function testRemoteAnnouncementFailureKeepsDbAndRuntimeCommitted(bool $inHandler): void
    {
        $this->mountFixture();
        $this->mountRuntimeFixture();
        $first = Hilos::$db->remote[1];
        $failure = new RuntimeException('remote reaction failed');
        SourceChangeBus::subscribe(new NamedFixtureFailingReaction($failure));
        if (!$inHandler) {
            self::assertSame([], Database::handlerEnd());
        }
        Database::useConnection(1);
        $caught = null;
        try {
            Database::transaction('named_test_remote', function (): void {
                Hilos::$db->remote[1]->actions->rename('committed');
                Hilos::$rt->namedRuntime->actions->create('added', 'committed');
                self::assertSame([], $this->reaction->seen);
                self::assertNull(Hilos::$sr->getNextQueuedSignal());
            });
            self::assertTrue($inHandler);
        } catch (AnnouncementFailedException $card) {
            self::assertFalse($inHandler);
            $caught = $card;
        }
        if ($inHandler) {
            $owed = Database::handlerEnd();
            self::assertCount(1, $owed);
            self::assertInstanceOf(AnnouncementFailedException::class, $owed[0]);
            $caught = $owed[0];
        }
        self::assertNotNull($caught);
        self::assertSame($failure, $caught->getPrevious()?->getPrevious());
        self::assertSame('committed', self::rawLabels(2, 2)[0]);
        self::assertSame($first, Hilos::$db->remote[1]);
        self::assertSame('committed', $first->label);
        self::assertSame('committed', Hilos::$rt->namedRuntime['added']->label());
        self::assertSame(['remote', 'namedRuntime'], array_column($this->reaction->seen, 'sourceKey'));
        self::assertSame([SignalConstants::DB_SYNC_UPDATED, SignalConstants::RT_SYNC_CREATED], $this->drainFrames());
        self::assertTrue(Hilos::$sr->shouldSkipDbSyncApply('remote', '1', Hilos::$sr->getEmitter()));
        self::assertTrue(Hilos::$sr->shouldSkipRtSyncApply('namedRuntime', 'added'));
        self::assertFalse(Hilos::$sr->shouldSkipDbSyncApply('remote', '1', Hilos::$sr->getEmitter()));
        self::assertFalse(Hilos::$sr->shouldSkipRtSyncApply('namedRuntime', 'added'));
        self::assertFalse(Database::hasOpenTransactions());
        $this->assertAmbientDefaultRestored(1);
    }

    /** Refusing a nested named start keeps its parent's real ORM work until handler cleanup. */
    public function testRemoteStartRefusalLeavesItsParentForAllConnectionHandlerCleanup(): void
    {
        $this->mountFixture();
        $this->mountRuntimeFixture();
        $first = Hilos::$db->remote[1];
        Database::useConnection(1);
        Database::transactionStart(2);
        Hilos::$db->remote[1]->actions->rename('parent');
        try {
            Database::transaction('named_test_remote', static function (): void {
                self::fail('A refused start must never enter its callback');
            });
            self::fail('The remote parent must refuse nesting');
        } catch (NestedTransactionRefusedException) {
            self::assertSame('parent', self::rawLabels(2, 2)[0]);
            self::assertSame('parent', $first->label);
            self::assertTrue(Database::hasOpenTransactions());
        }
        Database::transactionStart(0);
        Database::onRollback(static function (): void {}, 0);
        Database::transactionStart(1);
        Hilos::$rt->namedRuntime->actions->create('added', 'ambient handler');
        $owed = Database::handlerEnd();
        self::assertCount(1, $owed);
        self::assertInstanceOf(TransactionLeftOpenException::class, $owed[0]);
        foreach ([0, 1, 2] as $index) {
            self::assertStringContainsString("connection {$index}", $owed[0]->getMessage());
        }
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame('remote-1', self::rawLabels(2, 2)[0]);
        self::assertSame($first, Hilos::$db->remote[1]);
        self::assertSame('remote-1', $first->label);
        self::assertNull(Hilos::$rt->namedRuntime['added']);
        $this->assertNoReleasedAnnouncements();
        $this->assertAmbientDefaultRestored(1);
    }

    /**
     * Clear keeps its existing emitter-only guard and follows the named remote commit.
     *
     * @param bool $commit Whether to commit the bulk deletion
     */
    #[DataProvider('transactionOutcomes')]
    public function testRemoteCollectionClearKeepsItsEmitterOnlyEchoRule(bool $commit): void
    {
        $this->mountFixture();
        Hilos::$db->remote->getObjectCollection()->preloadAll();
        $first = Hilos::$db->remote[1];
        $before = self::allRawLabels();
        $failure = new RuntimeException('cancel remote clear');
        Database::useConnection(1);
        try {
            Database::transaction('named_test_remote', function () use ($commit, $failure): void {
                Hilos::$db->remote->actions->deleteAll();
                self::assertSame([], self::rawLabels(2, 2));
                self::assertSame([], Hilos::$db->remote->getObjectCollection()->keys());
                self::assertNull(Hilos::$sr->getNextQueuedSignal());
                self::assertSame([], $this->reaction->seen);
                if (!$commit) {
                    throw $failure;
                }
            });
            self::assertTrue($commit);
        } catch (RuntimeException $caught) {
            self::assertFalse($commit);
            self::assertSame($failure, $caught);
        }
        self::assertSame($before['primary'], self::rawLabels(0, 0));
        self::assertSame($before['local'], self::rawLabels(1, 0));
        if ($commit) {
            self::assertSame([], self::rawLabels(2, 2));
            $signal = Hilos::$sr->getNextQueuedSignal();
            self::assertNotNull($signal);
            self::assertSame(SignalConstants::DB_SYNC_CLEARED, $signal->signalType->getType());
            self::assertSame([
                SyncSignalDataKey::COLLECTION_KEY => 'remote',
                SyncSignalDataKey::ORIGIN => null,
                SyncSignalDataKey::ORIGIN_REQUEST_ID => null,
                SyncSignalDataKey::EMITTER => Hilos::$sr->getEmitter(),
            ], $signal->data->toArray());
            self::assertTrue(Hilos::$sr->shouldSkipDbSyncClearApply(Hilos::$sr->getEmitter()));
            self::assertTrue(Hilos::$sr->shouldSkipDbSyncClearApply(Hilos::$sr->getEmitter()));
            self::assertFalse(Hilos::$sr->shouldSkipDbSyncClearApply('foreign-emitter'));
            self::assertFalse(Hilos::$sr->shouldSkipDbSyncClearApply(null));
        } else {
            self::assertSame($before, self::allRawLabels());
            self::assertSame($first->getObject(), Hilos::$db->remote[1]->getObject());
            self::assertSame('remote-1', $first->label);
            self::assertTrue(Hilos::$db->remote->getObjectCollection()->isAllLoaded());
            self::assertSame([], $this->reaction->seen);
        }
        self::assertFalse(Hilos::$sr->shouldSkipDbSyncApply('remote', '1', Hilos::$sr->getEmitter()));
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertAmbientDefaultRestored(1);
    }

    /** Mounts runtime through its normal representation and truth-source write doors. */
    private function mountRuntimeFixture(): void
    {
        Hilos::$rt = new NamedFixtureRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        RtTruthSourceRegistry::register('namedRuntime', TruthSourceKeys::all(), 'named-fixture');
        Hilos::$rt->namedRuntime->actions->create('kept', 'before');
        Hilos::$rt->namedRuntime->actions->create('dropped', 'dropped');
        // Isolate the transaction's frames and echo registrations from fixture setup.
        Hilos::$sr = new SignalRouter();
        $this->reaction->seen = [];
        $this->mirror->seen = [];
    }

    /** @return DbItem Insert whose identity is inspected after commit or rollback */
    private function mutateRemoteAndRuntime(): DbItem
    {
        Hilos::$db->remote[1]->actions->rename('changed');
        $created = Hilos::$db->remote->actions->create('added', 99);
        Hilos::$db->remote[2]->actions->delete();
        Hilos::$rt->namedRuntime['kept']->actions->rename('changed');
        Hilos::$rt->namedRuntime->actions->create('added', 'added');
        Hilos::$rt->namedRuntime['dropped']->actions->delete();
        return $created;
    }

    /** Undo also tells mirrors about the three rows read only during preloadAll(). */
    private function assertRemoteRollbackMirrors(): void
    {
        self::assertCount(13, $this->mirror->seen);
        self::assertSame(['3', '4', '5', 'dropped', 'added', '2', '300', '1'],
            array_column(array_slice($this->mirror->seen, 5), SourceChange::FIELD_SOURCE_ID));
    }

    /** No reaction, frame or row echo may survive an uncommitted or discarded transaction. */
    private function assertNoReleasedAnnouncements(): void
    {
        self::assertSame([], $this->reaction->seen);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        foreach (['1', '2', '300'] as $id) {
            self::assertFalse(Hilos::$sr->shouldSkipDbSyncApply('remote', $id, Hilos::$sr->getEmitter()));
        }
        foreach (['kept', 'added', 'dropped'] as $id) {
            self::assertFalse(Hilos::$sr->shouldSkipRtSyncApply('namedRuntime', $id));
        }
    }

    /** Source order and exact wire keys retain the existing DB emitter and RT key echo rules. */
    private function assertRemoteAnnouncementsReleased(): void
    {
        self::assertSame(['remote', 'remote', 'remote', 'namedRuntime', 'namedRuntime'],
            array_column($this->reaction->seen, 'sourceKey'));
        $expected = [
            [SignalConstants::DB_SYNC_UPDATED, 'remote', '1'],
            [SignalConstants::DB_SYNC_CREATED, 'remote', '300'],
            [SignalConstants::DB_SYNC_DELETED, 'remote', '2'],
            [SignalConstants::RT_SYNC_UPDATED, 'namedRuntime', 'kept'],
            [SignalConstants::RT_SYNC_CREATED, 'namedRuntime', 'added'],
            [SignalConstants::RT_SYNC_DELETED, 'namedRuntime', 'dropped'],
        ];
        foreach ($expected as [$name, $key, $id]) {
            $signal = Hilos::$sr->getNextQueuedSignal();
            self::assertNotNull($signal);
            self::assertSame($name, $signal->signalType->getType());
            $payload = $signal->data->toArray();
            $db = $key === 'remote';
            self::assertSame($key, $payload[SyncSignalDataKey::COLLECTION_KEY]);
            self::assertSame($id, $payload[$db ? SyncSignalDataKey::ID_STRING : SyncSignalDataKey::STATE_ID]);
            $keys = [SyncSignalDataKey::COLLECTION_KEY, $db ? SyncSignalDataKey::ID_STRING : SyncSignalDataKey::STATE_ID,
                SyncSignalDataKey::ROW, SyncSignalDataKey::ORIGIN, SyncSignalDataKey::ORIGIN_REQUEST_ID];
            if ($db) {
                $keys[] = SyncSignalDataKey::EMITTER;
            }
            if (in_array($name, [SignalConstants::DB_SYNC_UPDATED, SignalConstants::RT_SYNC_UPDATED], true)) {
                $keys[] = SyncSignalDataKey::PREVIOUS;
            }
            sort($keys);
            $actualKeys = array_keys($payload);
            sort($actualKeys);
            self::assertSame($keys, $actualKeys, 'Connection indexes must stay out of the wire contract');
            if ($db) {
                self::assertSame(Hilos::$sr->getEmitter(), $payload[SyncSignalDataKey::EMITTER]);
                self::assertFalse(Hilos::$sr->shouldSkipDbSyncApply($key, $id, 'foreign-emitter'));
                self::assertTrue(Hilos::$sr->shouldSkipDbSyncApply($key, $id, $payload[SyncSignalDataKey::EMITTER]));
                self::assertFalse(Hilos::$sr->shouldSkipDbSyncApply($key, $id, $payload[SyncSignalDataKey::EMITTER]));
            } else {
                self::assertTrue(Hilos::$sr->shouldSkipRtSyncApply($key, $id));
                self::assertFalse(Hilos::$sr->shouldSkipRtSyncApply($key, $id));
            }
        }
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @param int $ambient Expected legacy default outside the named scope
     */
    private function assertAmbientDefaultRestored(int $ambient): void
    {
        self::assertSame($ambient, Database::getCurrentIndex());
        $released = false;
        Database::transactionStart($ambient);
        Database::afterCommit(static function () use (&$released): void { $released = true; });
        self::assertFalse($released, 'A leaked named default would announce on another empty stack');
        Database::transactionCommit($ambient);
        self::assertTrue($released);
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** Kills remote2 from another session of the same remote user, never from primary. */
    private function killRemoteSession(): void
    {
        $session = (int) Database::sql('SELECT CONNECTION_ID() AS id', index: 2)->field('id');
        $remote = Database::getConnectionConfig(2);
        Database::configure(index: 3, host: $remote->host, user: $remote->user, password: $remote->password,
            database: $remote->database, port: $remote->port, charset: $remote->charset, socket: $remote->socket);
        Database::connect(3);
        try {
            self::assertNotSame($session, (int) Database::sql('SELECT CONNECTION_ID() AS id', index: 3)->field('id'));
            Database::sql('KILL ?', [$session], index: 3);
        } finally {
            Database::close(3);
        }
    }

    /** Creates only test-local parent DDL; the child reuses the remote sentinel table's position column. */
    private function mountSetFixture(): void
    {
        Database::handlerEnd();
        Database::sql('DROP TABLE IF EXISTS ' . NamedSetParentEntity::getSqlTableName(), index: 2);
        Database::sql('CREATE TABLE ' . NamedSetParentEntity::getSqlTableName()
            . ' (`id` INT NOT NULL PRIMARY KEY, `label` VARCHAR(80) NOT NULL, `position` INT NOT NULL) ENGINE=InnoDB', index: 2);
        $this->createdSetParent = true;
        Schema::reset(2);
        Database::sql('INSERT INTO ' . NamedSetParentEntity::getSqlTableName()
            . " (`id`, `label`, `position`) VALUES (10, 'parent', 77)", index: 2);
        Database::handlerStart();
        Hilos::$db = new NamedSetFixtureDbContext();
        Hilos::$db->configure();
        TruthSourceRegistry::register('namedSetChildren', TruthSourceKeys::set('77'), 'named-set-fixture');
        ExecutionContext::setCurrentAgentId('named-set-fixture');
    }

    /**
     * Dispatches real writes through collection, item-precondition and direct Object doors.
     *
     * @param string $door Write entrypoint
     * @param NamedPrimaryItem $first Loaded child whose parent is initially cold
     * @param NamedSetChildObject $insert Unsaved child draft
     */
    private function writeWithSetClaim(string $door, NamedPrimaryItem $first, NamedSetChildObject $insert): void
    {
        switch ($door) {
            case 'createInSet':
                Hilos::$db->namedSetChildren->actions->createInSet('allowed', 10);
                break;
            case 'updateSet':
                Hilos::$db->namedSetChildren->actions->updateSet(10, 'allowed');
                break;
            case 'removeSet':
                Hilos::$db->namedSetChildren->actions->removeSet(10);
                break;
            case 'updateItem':
                $first->actions->rename('allowed');
                break;
            case 'removeItem':
                $first->actions->delete();
                break;
            case 'syncObject':
                $first->getObject()->changeLabel('allowed');
                $first->getObject()->sync();
                break;
            case 'insertObject':
                $insert->sync();
                break;
            case 'deleteObject':
                $first->getObject()->delete();
                break;
        }
    }

    /** @param string $door Operation whose persisted effect is checked independently */
    private function assertSetWritePersisted(string $door): void
    {
        $labels = self::labels('remote', 5);
        if (in_array($door, ['createInSet', 'insertObject'], true)) {
            $labels[] = 'allowed';
        } elseif (in_array($door, ['removeSet', 'removeItem', 'deleteObject'], true)) {
            array_shift($labels);
        } else {
            $labels[0] = 'allowed';
        }
        self::assertSame($labels, self::rawLabels(2, 2));
        self::assertSame(self::labels('primary', 3), self::rawLabels(0, 0));
        self::assertSame(self::labels('local', 4), self::rawLabels(1, 0));
    }

    /** @param int $strategy Loading strategy shared by the three test mounts */
    private function mountFixture(int $strategy = Objects::LAZY_STRATEGY_KEY): void
    {
        Hilos::$db = new NamedFixtureDbContext($strategy);
        Hilos::$db->configure();
    }

    /** @return list<string> Queued frame names */
    private function drainFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $signal->signalType->getType();
        }
        return $frames;
    }

    /** @return array<string, list<string>> Independent indexed SQL inspection of every physical target */
    private static function allRawLabels(): array
    {
        $labels = [];
        foreach (self::TARGETS as [, $prefix, , $rowIndex, $schemaIndex]) {
            $labels[$prefix] = self::rawLabels($schemaIndex, $rowIndex);
        }
        return $labels;
    }

    /** @return list<string> Labels on an explicitly addressed physical schema and server */
    private static function rawLabels(int $schemaIndex, int $rowIndex): array
    {
        return array_column(Database::sql(
            'SELECT `label` FROM ' . self::fixtureTable($schemaIndex) . ' ORDER BY `id`', index: $rowIndex,
        )->rows(), 'label');
    }

    /** @return string Fixture address from physical configuration, independently of Entity binding */
    private static function fixtureTable(int $schemaIndex): string
    {
        return '`' . str_replace('`', '``', Database::getConnectionConfig($schemaIndex)->database)
            . '`.`named_database_fixture`';
    }

    /** @return list<string> Ordered fixture sentinel labels */
    private static function labels(string $prefix, int $count): array
    {
        return array_map(static fn (int $id): string => "{$prefix}-{$id}", range(1, $count));
    }

    /** @return string Required remote stand configuration; missing values fail setup */
    private static function remoteEnvironment(string $key): string
    {
        $value = getenv($key);
        self::assertIsString($value, "Missing {$key}");
        self::assertNotSame('', $value, "Empty {$key}");
        return $value;
    }
}

/** Test-local scalar row, intentionally outside production Entity discovery. */
class NamedPrimaryEntity extends Entity
{
    public const string _table = 'named_database_fixture';
    public const string _primary = 'id';
    public const array _columns = ['id', 'label', 'position'];
    public const array _types = [
        'id' => PhpType::INTEGER->value,
        'label' => PhpType::STRING->value,
        'position' => PhpType::INTEGER->value,
    ];

    public ?int $id = null;
    public string $label = '';
    public int $position = 0;
}

final class NamedLocalEntity extends NamedPrimaryEntity
{
    public const string _database = DatabaseNames::CHANGE_LOG;
}

final class NamedRemoteEntity extends NamedPrimaryEntity
{
    public const string _database = 'named_test_remote';
}

/** @extends EntityCollection<NamedPrimaryEntity> */
final class NamedPrimaryEntities extends EntityCollection
{
    public const string ENTITY_CLASS = NamedPrimaryEntity::class;
}

/** @extends EntityCollection<NamedLocalEntity> */
final class NamedLocalEntities extends EntityCollection
{
    public const string ENTITY_CLASS = NamedLocalEntity::class;
}

/** @extends EntityCollection<NamedRemoteEntity> */
final class NamedRemoteEntities extends EntityCollection
{
    public const string ENTITY_CLASS = NamedRemoteEntity::class;
}

/**
 * @property-read NamedPrimaryCollection $primary
 * @property-read NamedLocalCollection $local
 * @property-read NamedRemoteCollection $remote
 */
final class NamedFixtureDbContext extends HilosDbContext
{
    /** @param int $strategy Loading strategy for this fixture context */
    public function __construct(private readonly int $strategy)
    {
        parent::__construct();
    }

    /** Mounts only the fixture chains, without framework table discovery. */
    public function configure(): void
    {
        foreach ([NamedPrimaryObjects::class, NamedLocalObjects::class, NamedRemoteObjects::class] as $class) {
            $this->mountObjectCollection($class, $this->strategy);
        }
        $this->setRepresent('primary', NamedPrimaryCollection::class, NamedFixtureCollectionActions::class, NamedFixtureItemActions::class);
        $this->setRepresent('local', NamedLocalCollection::class, NamedFixtureCollectionActions::class, NamedFixtureItemActions::class);
        $this->setRepresent('remote', NamedRemoteCollection::class, NamedFixtureCollectionActions::class, NamedFixtureItemActions::class);
    }
}

/**
 * @property-read ?int $id
 * @property-read string $label
 * @property-read int $position
 */
class NamedPrimaryObject extends Object_
{
    public const string ENTITY_CLASS = NamedPrimaryEntity::class;
    public const string OBJECT_COLLECTION_CLASS = NamedPrimaryObjects::class;

    /**
     * @param string $property Fixture scalar field
     * @return mixed Current scalar or inherited property
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            'id', 'label', 'position' => $this->entity->{$property},
            default => parent::__get($property),
        };
    }

    /** @param string $label Draft label, saved by sync() */
    public function changeLabel(string $label): void
    {
        $this->entity->label = $label;
    }

    /** @param int $position Draft order value, saved by sync() */
    public function changePosition(int $position): void
    {
        $this->entity->position = $position;
    }
}

final class NamedLocalObject extends NamedPrimaryObject
{
    public const string ENTITY_CLASS = NamedLocalEntity::class;
    public const string OBJECT_COLLECTION_CLASS = NamedLocalObjects::class;
}

final class NamedRemoteObject extends NamedPrimaryObject
{
    public const string ENTITY_CLASS = NamedRemoteEntity::class;
    public const string OBJECT_COLLECTION_CLASS = NamedRemoteObjects::class;
}

/** @extends Objects<NamedPrimaryObject> */
class NamedPrimaryObjects extends Objects
{
    public const string OBJECT_CLASS = NamedPrimaryObject::class;
    public const string ENTITY_COLLECTION_CLASS = NamedPrimaryEntities::class;
    public const string COLLECTION_KEY = 'primary';
}

final class NamedLocalObjects extends NamedPrimaryObjects
{
    public const string OBJECT_CLASS = NamedLocalObject::class;
    public const string ENTITY_COLLECTION_CLASS = NamedLocalEntities::class;
    public const string COLLECTION_KEY = 'local';
}

final class NamedRemoteObjects extends NamedPrimaryObjects
{
    public const string OBJECT_CLASS = NamedRemoteObject::class;
    public const string ENTITY_COLLECTION_CLASS = NamedRemoteEntities::class;
    public const string COLLECTION_KEY = 'remote';
}

/**
 * @property-read ?int $id
 * @property-read string $label
 * @property-read int $position
 * @property-read NamedFixtureItemActions $actions
 */
class NamedPrimaryItem extends DbItem
{
    /**
     * @param string $name Fixture scalar field or inherited property
     * @return mixed Scalar field or inherited property
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'id', 'label', 'position' => $this->_object->valueForColumn($name),
            default => parent::__get($name),
        };
    }
}

final class NamedLocalItem extends NamedPrimaryItem
{
}

final class NamedRemoteItem extends NamedPrimaryItem
{
}

/**
 * Array access uses the fixture's integer primary id.
 *
 * @property-read NamedFixtureCollectionActions $actions
 * @extends DbCollection<NamedPrimaryItem, NamedPrimaryObjects>
 */
class NamedPrimaryCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = NamedPrimaryItem::class;
    public const string OBJECT_COLLECTION_CLASS = NamedPrimaryObjects::class;
}

final class NamedLocalCollection extends NamedPrimaryCollection
{
    public const string DB_ITEM_CLASS = NamedLocalItem::class;
    public const string OBJECT_COLLECTION_CLASS = NamedLocalObjects::class;
}

final class NamedRemoteCollection extends NamedPrimaryCollection
{
    public const string DB_ITEM_CLASS = NamedRemoteItem::class;
    public const string OBJECT_COLLECTION_CLASS = NamedRemoteObjects::class;
}

/**
 * @property-read NamedPrimaryObjects $objectCollection */
final class NamedFixtureCollectionActions extends CollectionDbActions
{
    /**
     * @param string $label Initial label
     * @param int $position Initial ordering value
     * @return DbItem Wrapper over the saved fixture object
     */
    public function create(string $label, int $position): DbItem
    {
        $this->ensureCanCreate();
        $object = ($this->objectCollection::OBJECT_CLASS)::create();
        $object->changeLabel($label);
        $object->changePosition($position);
        $object->sync();
        $this->addObjectToCollection($object);
        return $this->createDbItemFromObject($object);
    }

    /** Deletes the fixture's whole table through the shared bulk door. */
    public function deleteAll(): void
    {
        $this->deleteAllObjects();
    }

    /**
     * A raw mutation immediately after each existing action guard proves that guard is early enough.
     *
     * @param string $door Guard the custom action calls before its own SQL
     */
    public function customSqlAfterGuard(string $door): void
    {
        match ($door) {
            'writeGuard' => $this->ensureCanWrite(TruthSourceOperation::Update),
            'setGuard' => $this->ensureCanWriteSet('1', TruthSourceOperation::Update),
            'createGuard' => $this->ensureCanCreate(),
            'createSetGuard' => $this->ensureCanCreateInSet('1'),
        };
        Database::sql('UPDATE ' . NamedRemoteEntity::getSqlTableName() . " SET `label` = 'guard bypass'", index: 2);
    }
}

/**
 * @property-read NamedPrimaryObject $object */
final class NamedFixtureItemActions extends ItemDbActions
{
    /** @param string $label New value saved through the Object sync door */
    public function rename(string $label): void
    {
        $this->ensureCanWrite();
        $this->object->changeLabel($label);
        $this->object->sync();
    }

    /** Removes the loaded row and its collection membership. */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $id = $this->object->getIdString();
        $this->object->delete();
        unset($this->getObjectCollection()[$id]);
    }
}

class NamedFixtureReaction implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Observed facts */
    public array $seen = [];

    /**
     * @param SourceChange $change Fact observed by this subscriber
     * @param SourceChangeProvenance $provenance Original provenance, not read here
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $change;
    }
}

final class NamedFixtureMirror extends NamedFixtureReaction implements SourceMirrorSubscriberInterface
{
}

/**
 * Test-local runtime context; no production runtime row contract is added.
 *
 * @property-read NamedFixtureRtCollection $namedRuntime
 */
final class NamedFixtureRtContext extends RtContext
{
    public const string namedRuntime = 'namedRuntime';

    /** Mounts backing state together with its View and both action layers. */
    public function configure(): void
    {
        $this->_stateCollections[self::namedRuntime] = NamedFixtureRtStates::init();
        $this->setRepresent(self::namedRuntime, NamedFixtureRtCollection::class,
            NamedFixtureRtCollectionActions::class, NamedFixtureRtItemActions::class);
    }
}

/** Runtime row used only to exercise the existing transaction undo and sync doors. */
final class NamedFixtureRtState extends RtState
{
    public const string id = 'id';
    public const string label = 'label';

    public string $id;
    public string $label;

    /**
     * @param string $id Runtime key
     * @param string $label Initial value
     * @return static Row with its sync baseline initialized
     */
    public static function create(string $id, string $label): static
    {
        $state = new static();
        $state->id = $id;
        $state->label = $label;
        $state->markRtSyncBaseline();
        return $state;
    }

    /**
     * @param array<string, mixed> $row Full runtime row
     * @return static Hydrated row
     */
    public static function fromRow(array $row): static
    {
        return static::create(self::requireString($row, self::id), self::requireString($row, self::label));
    }

    /** @return string Mounted collection key */
    public static function getRtCollectionKey(): string
    {
        return NamedFixtureRtContext::namedRuntime;
    }

    /** @return string Runtime row key */
    public function getId(): string
    {
        return $this->id;
    }

    /** @param array<string, mixed> $diff Partial update or rollback values */
    public function applyDiff(array $diff): void
    {
        $this->label = self::patchString($diff, self::label, $this->label);
    }

    /** @return array<string, mixed> Full runtime row */
    public function toArray(): array
    {
        return [self::id => $this->id, self::label => $this->label];
    }
}

/** @extends RtStates<NamedFixtureRtState> */
final class NamedFixtureRtStates extends RtStates
{
    public const string STATE_CLASS = NamedFixtureRtState::class;

    /**
     * @param ?string $id Optional row key
     * @return ?NamedFixtureRtState Existing state, or null
     */
    public function get(?string $id): ?NamedFixtureRtState
    {
        return parent::get($id);
    }

    /**
     * @param mixed $offset Required row key
     * @return NamedFixtureRtState Existing state
     */
    public function offsetGet(mixed $offset): NamedFixtureRtState
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Named runtime fixture row not found: null');
        }
        return $this->get((string) $offset)
            ?? throw new OutOfBoundsException("Named runtime fixture row not found: {$offset}");
    }
}

/**
 * @extends RtItem<NamedFixtureRtState>
 * @property-read NamedFixtureRtItemActions $actions
 */
final class NamedFixtureRtItem extends RtItem
{
    /**
     * @param string $name Requested View property
     * @return mixed Item actions or an inherited property
     */
    public function __get(string $name): mixed
    {
        return $name === 'actions' ? $this->getItemActions() : parent::__get($name);
    }

    /** @return string Current value of the wrapped state, including after undo */
    public function label(): string
    {
        return $this->_state->label;
    }

    /** @return array<string, mixed> Full runtime row */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

/** @extends RtCollection<NamedFixtureRtItem, NamedFixtureRtCollectionActions> */
final class NamedFixtureRtCollection extends RtCollection
{
    /**
     * @param RtState $state State read from the mounted collection
     * @return RtItem View retaining the state instance
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new NamedFixtureRtItem($state);
    }
}

/**
 * @extends CollectionRtActions<NamedFixtureRtItem, NamedFixtureRtCollection, NamedFixtureRtStates>
 * @property-read NamedFixtureRtStates $stateCollection
 */
final class NamedFixtureRtCollectionActions extends CollectionRtActions
{
    /**
     * @param string $id New row key
     * @param string $label Initial value
     */
    public function create(string $id, string $label): void
    {
        $this->addStateToCollection(NamedFixtureRtState::create($id, $label));
    }
}

/**
 * @extends ItemRtActions<NamedFixtureRtItem, NamedFixtureRtState>
 * @property-read NamedFixtureRtState $state
 */
final class NamedFixtureRtItemActions extends ItemRtActions
{
    /** @param string $label New value */
    public function rename(string $label): void
    {
        $this->ensureCanWrite();
        $this->state->label = $label;
        $this->sync();
    }

    /** Deletes through the base item action and its ownership check. */
    public function delete(): void
    {
        $this->remove();
    }
}

/** A reaction failure injected after the observer and outbound runtime subscriber. */
final class NamedFixtureFailingReaction implements SourceChangeSubscriberInterface
{
    /** @param RuntimeException $failure Original failure to retain through the announcement wrapper */
    public function __construct(private readonly RuntimeException $failure)
    {
    }

    /**
     * @param SourceChange $change Committed fact
     * @param SourceChangeProvenance $provenance Original provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        throw $this->failure;
    }
}

/** Test-local placement fixtures; coverage is independent from the supported journal DDL topology. */
final class NamedCoverageLocalEntity extends NamedPrimaryEntity
{
    public const string _database = DatabaseNames::CHANGE_LOG;
    public const bool _journaled = true;
    public const array _pii = ['label' => AnonymizationStrategy::MASK];
    public const array _piiNotPersonal = ['id', 'position'];
}

final class NamedCoverageRemoteEntity extends NamedPrimaryEntity
{
    public const string _database = 'named_test_remote';
    public const bool _journaled = true;
    public const array _pii = ['label' => AnonymizationStrategy::HASH];
    public const array _piiNotPersonal = ['id', 'position'];
}

final class NamedCoverageLocalObject extends Object_
{
    public const string ENTITY_CLASS = NamedCoverageLocalEntity::class;
}

final class NamedCoverageRemoteObject extends Object_
{
    public const string ENTITY_CLASS = NamedCoverageRemoteEntity::class;
}

final class NamedCoverageLocalRows extends Objects
{
    public const string OBJECT_CLASS = NamedCoverageLocalObject::class;
}

final class NamedCoverageRemoteRows extends Objects
{
    public const string OBJECT_CLASS = NamedCoverageRemoteObject::class;
}

final class NamedCoverageContext extends HilosDbContext
{
    /** Mounts only the two journaled fixture chains without loading their rows. */
    public function configure(): void
    {
        $this->_objectCollections['coverage_local'] = NamedCoverageLocalRows::initEmpty();
        $this->_objectCollections['coverage_remote'] = NamedCoverageRemoteRows::initEmpty();
    }
}

final class NamedRemoteAliasEntity extends NamedPrimaryEntity
{
    public const string _database = 'named_remote_alias';
}

final class NamedSharedRowEntity extends NamedPrimaryEntity
{
    public const string _database = 'named_shared_row';
}

final class NamedSharedRowObject extends Object_
{
    public const string ENTITY_CLASS = NamedSharedRowEntity::class;
}

final class NamedSharedRowObjects extends Objects
{
    public const string OBJECT_CLASS = NamedSharedRowObject::class;
}

final class NamedPrimaryExtensionEntity extends NamedPrimaryEntity
{
    public const array _columns = [...parent::_columns, 'note'];
    public const array _types = [...parent::_types, 'note' => PhpType::STRING->value];

    public ?string $note = null;
}

final class NamedPrimaryExtensionObject extends Object_
{
    public const string ENTITY_CLASS = NamedPrimaryExtensionEntity::class;
}

final class NamedPrimaryExtensionRows extends Objects
{
    public const string OBJECT_CLASS = NamedPrimaryExtensionObject::class;
}

/** Isolates selected real Object/Entity links without loading rows or unrelated framework tables. */
final class NamedSchemaContext extends HilosDbContext
{
    /** @param list<class-string<Objects>> $collections Selected fixture chains */
    public function __construct(private readonly array $collections)
    {
        parent::__construct();
    }

    /** Mounts each selected chain for the schema and identity guards. */
    public function configure(): void
    {
        foreach ($this->collections as $key => $collection) {
            $this->_objectCollections[(string) $key] = $collection::initEmpty();
        }
    }
}

/** Test-local parent has a soft owner key; a child must read it to reach that key. */
final class NamedSetParentEntity extends NamedPrimaryEntity
{
    public const string _database = 'named_test_remote';
    public const string _table = 'named_database_set_parent';
    public const string _setVia = 'position';
    public const bool _setRoot = true;
}

final class NamedSetChildEntity extends NamedPrimaryEntity
{
    public const string _database = 'named_test_remote';
    public const string _setVia = 'position';
    public const bool _setRoot = false;
    public const array _foreign = ['position' => 'named_database_set_parent'];
}

final class NamedSetParentEntities extends EntityCollection
{
    public const string ENTITY_CLASS = NamedSetParentEntity::class;
}

final class NamedSetChildEntities extends EntityCollection
{
    public const string ENTITY_CLASS = NamedSetChildEntity::class;
}

final class NamedSetParentObject extends NamedPrimaryObject
{
    public const string ENTITY_CLASS = NamedSetParentEntity::class;
    public const string OBJECT_COLLECTION_CLASS = NamedSetParentObjects::class;
}

final class NamedSetChildObject extends NamedPrimaryObject
{
    public const string ENTITY_CLASS = NamedSetChildEntity::class;
    public const string OBJECT_COLLECTION_CLASS = NamedSetChildObjects::class;
}

/** The process-local loader counter observes attempts even when SQL fails before hydration. */
final class NamedSetParentObjects extends NamedPrimaryObjects
{
    public const string OBJECT_CLASS = NamedSetParentObject::class;
    public const string ENTITY_COLLECTION_CLASS = NamedSetParentEntities::class;
    public const string COLLECTION_KEY = 'namedSetParents';

    public int $loads = 0;

    /**
     * Counts every parent-loader attempt before using real Entity SQL.
     *
     * @param int|string|null $key Requested parent key
     * @return ?Object_ Parent loaded from the remote server
     */
    protected function lazyLoadObject(int|string|null $key): ?Object_
    {
        ++$this->loads;
        return parent::lazyLoadObject($key);
    }
}

final class NamedSetChildObjects extends NamedPrimaryObjects
{
    public const string OBJECT_CLASS = NamedSetChildObject::class;
    public const string ENTITY_COLLECTION_CLASS = NamedSetChildEntities::class;
    public const string COLLECTION_KEY = 'namedSetChildren';
}

final class NamedSetChildCollection extends NamedPrimaryCollection
{
    public const string OBJECT_COLLECTION_CLASS = NamedSetChildObjects::class;
}

/** @property-read NamedSetChildCollection $namedSetChildren */
final class NamedSetFixtureDbContext extends HilosDbContext
{
    /** Mounts the real DB-backed parent cold and the child with only a set-width writer. */
    public function configure(): void
    {
        $this->mountObjectCollection(NamedSetParentObjects::class, Objects::LAZY_STRATEGY_KEY);
        $this->mountObjectCollection(NamedSetChildObjects::class, Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent('namedSetChildren', NamedSetChildCollection::class,
            NamedSetFixtureCollectionActions::class, NamedFixtureItemActions::class);
    }
}

final class NamedSetFixtureCollectionActions extends CollectionDbActions
{
    /**
     * Inserts through the set precondition before Object sync and collection membership.
     *
     * @param string $label New child label
     * @param int $parentId Parent whose top must match the claim
     */
    public function createInSet(string $label, int $parentId): void
    {
        $this->ensureCanCreateInSet((string)$parentId);
        $object = NamedSetChildObject::create();
        $object->changeLabel($label);
        $object->changePosition($parentId);
        $object->sync();
        $this->addObjectToCollection($object);
    }

    /**
     * Exercises the set guard before custom bulk UPDATE SQL.
     *
     * @param int $parentId Set column value
     * @param string $label Label for every child in the set
     */
    public function updateSet(int $parentId, string $label): void
    {
        $this->ensureCanWriteSet((string)$parentId, TruthSourceOperation::Update);
        Database::sql('UPDATE ' . NamedSetChildEntity::getSqlTableName() . ' SET label = ? WHERE position = ?',
            [$label, $parentId], index: 2);
    }

    /**
     * Exercises the set guard before custom bulk DELETE SQL.
     *
     * @param int $parentId Set column value
     */
    public function removeSet(int $parentId): void
    {
        $this->ensureCanWriteSet((string)$parentId, TruthSourceOperation::Remove);
        Database::sql('DELETE FROM ' . NamedSetChildEntity::getSqlTableName() . ' WHERE position = ?', [$parentId], index: 2);
    }

    /**
     * Exposes the other two collection preconditions without granting whole-table rights.
     *
     * @param string $door Whole-table precondition
     */
    public function wholeTableGuard(string $door): void
    {
        match ($door) {
            'writeGuard' => $this->ensureCanWrite(TruthSourceOperation::Update),
            'createGuard' => $this->ensureCanCreate(),
        };
    }
}

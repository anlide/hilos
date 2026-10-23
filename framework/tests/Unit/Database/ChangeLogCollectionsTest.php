<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\Exception\ExternalWriterException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\View\Collection\ChangeLogTables;
use Hilos\Database\View\Collection\ChangeLogFields;
use Hilos\Database\Entity\Item\ChangeLogReceipt;
use Hilos\Database\View\Collection\ChangeLogReceipts;
use Hilos\Database\View\Collection\ChangeLogEntries;
use Hilos\Database\View\Collection\ChangeLogChanges;
use Hilos\Database\View\Collection\ChangeLogValues;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\UnboundedCollectionReadException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Filter\ColumnFilter;
use Hilos\Database\Filter\FilterOperator;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Exercises the shared cacheless policy through a fixture chain. Only Entity storage is doubled;
 * production Objects paging, hydration, View wrappers, sync and transaction announcements run.
 * Real journal chains are also mapped from scalar rows; database/facade freshness is integration acceptance.
 */
final class ChangeLogCollectionsTest extends TestCase
{
    private ?HilosDbContext $previousDb = null;
    private ?SignalRouter $previousRouter = null;

    /** @var array<string, mixed> Database configuration restored after each case */
    private array $previousConfiguration = [];

    private CachelessObjects $store;
    private CachelessCollection $view;
    private CachelessChanges $changes;

    /** Installs real ORM layers over deterministic Entity storage. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousRouter = Hilos::$sr;
        foreach (['configurations', 'databaseBindings', 'connections', 'resultSets', 'lastSql', 'closedByHand',
            'currentIndex', 'transactionIndex'] as $property) {
            $reflection = new ReflectionProperty(Database::class, $property);
            $this->previousConfiguration[$property] = $reflection->getValue();
            $reflection->setValue(null, match ($property) {
                'currentIndex' => 0,
                'transactionIndex' => null,
                default => [],
            });
        }
        CachelessEntity::$rows = [1 => ['id' => 1, 'mark' => 'first'], 2 => ['id' => 2, 'mark' => 'second']];
        CachelessEntity::$keyReads = 0;
        CachelessEntity::$pageLimits = [];
        CachelessEntity::$countCeilings = [];
        CachelessObject::$mappedRows = 0;
        $this->store = CachelessObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->view = CachelessCollection::init();
        $this->view->setObjectCollection($this->store);
        Hilos::$db = CachelessContext::over($this->store, $this->view);
        Hilos::$sr = new SignalRouter();
        TruthSourceRegistry::register(CachelessObjects::COLLECTION_KEY, TruthSourceKeys::all(), 'cacheless-test');
        SourceChangeBus::reset();
        $this->changes = new CachelessChanges();
        SourceChangeBus::subscribe($this->changes);
    }

    /** Releases transaction hooks before restoring process globals. */
    protected function tearDown(): void
    {
        Database::handlerEnd();
        TruthSourceRegistry::unregister(CachelessObjects::COLLECTION_KEY, 'cacheless-test');
        SourceChangeBus::reset();
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousRouter;
        foreach ($this->previousConfiguration as $property => $value) {
            new ReflectionProperty(Database::class, $property)->setValue(null, $value);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{class-string<DbCollection>, array<string, mixed>}> Real journal chains and complete scalar rows */
    public static function journalRows(): iterable
    {
        yield 'Table values' => [ChangeLogTables::class, [
            'id' => 73,
            'name' => 'example_name',
        ]];
        yield 'Field values' => [ChangeLogFields::class, [
            'id' => 73,
            'table_id' => 11,
            'name' => 'example_name',
        ]];
        yield 'Receipt values' => [ChangeLogReceipts::class, [
            'id' => 73,
            'created_at' => '2026-10-23 12:34:56.123456',
            'actor_user_id' => 19,
            'subject_user_id' => 23,
            'session_id' => 29,
            'channel' => 'page',
            'action' => 'rename',
            'agent' => 'hilos_change_log',
            'source' => 'page:users',
            'open_handovers' => 2,
        ]];
        yield 'Receipt nulls' => [ChangeLogReceipts::class, [
            'id' => 73,
            'created_at' => '2026-10-23 12:34:56.123456',
            'actor_user_id' => null,
            'subject_user_id' => null,
            'session_id' => null,
            'channel' => 'page',
            'action' => 'rename',
            'agent' => null,
            'source' => null,
            'open_handovers' => 2,
        ]];
        yield 'Entry values' => [ChangeLogEntries::class, [
            'id' => 73,
            'created_at' => '2026-10-23 12:34:56.123456',
            'receipt_id' => 31,
            'table_id' => 11,
            'record_key' => '["☃", ""]',
            'record_key_hash' => "\0\xff" . str_repeat("\x80", 30),
            'mutation_type' => 'update',
        ]];
        yield 'Entry nulls' => [ChangeLogEntries::class, [
            'id' => 73,
            'created_at' => '2026-10-23 12:34:56.123456',
            'receipt_id' => null,
            'table_id' => 11,
            'record_key' => '["☃", ""]',
            'record_key_hash' => null,
            'mutation_type' => 'update',
        ]];
        yield 'Change values' => [ChangeLogChanges::class, [
            'id' => 73,
            'created_at' => '2026-10-23 12:34:56.123456',
            'log_id' => 37,
            'field_id' => 41,
            'kind' => 'inline',
            'old_present' => false,
            'new_present' => true,
            'old_value' => '',
            'new_value' => 'changed ☃',
        ]];
        yield 'Change nulls' => [ChangeLogChanges::class, [
            'id' => 73,
            'created_at' => '2026-10-23 12:34:56.123456',
            'log_id' => 37,
            'field_id' => 41,
            'kind' => 'inline',
            'old_present' => false,
            'new_present' => true,
            'old_value' => null,
            'new_value' => null,
        ]];
        yield 'Value values' => [ChangeLogValues::class, [
            'id' => 73,
            'created_at' => '2026-10-23 12:34:56.123456',
            'change_id' => 43,
            'old_value' => '',
            'new_value' => 'changed ☃',
        ]];
        yield 'Value nulls' => [ChangeLogValues::class, [
            'id' => 73,
            'created_at' => '2026-10-23 12:34:56.123456',
            'change_id' => 43,
            'old_value' => null,
            'new_value' => null,
        ]];
    }

    /**
     * @param class-string<DbCollection> $viewClass Real journal View collection
     * @param array<string, mixed> $row Expected scalar row, including nulls, binary bytes and microseconds
     */
    #[DataProvider('journalRows')]
    public function testRealJournalChainsPreserveScalarsAndLogicalId(string $viewClass, array $row): void
    {
        $storeClass = $viewClass::OBJECT_COLLECTION_CLASS;
        $objectClass = $storeClass::OBJECT_CLASS;
        $entityClass = $objectClass::ENTITY_CLASS;
        $viewItemClass = $viewClass::DB_ITEM_CLASS;
        $wire = array_map(static fn(mixed $value): mixed => is_int($value) || is_bool($value) ? (string)(int)$value : $value, $row);
        $entity = $entityClass::fromRow($wire);
        $object = $objectClass::fromEntity($entity);
        $item = new $viewItemClass($object);
        self::assertSame($row, $entity->toArray());
        self::assertSame($row, $object->toArray());
        self::assertSame($row, $item->toArray());
        foreach ($row as $field => $value) {
            self::assertSame($value, $object->$field);
            self::assertSame($value, $item->$field);
        }
        self::assertSame('73', $object->getIdString());
        self::assertSame('73', $item->getIdString());
        self::assertSame(isset($row['created_at']) ? ['id', 'created_at'] : ['id'], $object->getPrimaryKeyArrayKeys());
        self::assertSame(DatabaseNames::CHANGE_LOG, $entityClass::_database);
        self::assertFalse($entityClass::_journaled);
        self::assertSame(Entity::SET_STANDALONE, $entityClass::_setVia);
        self::assertFalse($entityClass::_setRoot);
        self::assertSame($entityClass !== ChangeLogReceipt::class, $entityClass::_externalWriter);
        self::assertSame($entityClass, ($storeClass::ENTITY_COLLECTION_CLASS)::ENTITY_CLASS);
        self::assertSame($storeClass, $objectClass::OBJECT_COLLECTION_CLASS);
        self::assertSame(lcfirst(substr($viewClass, strrpos($viewClass, '\\') + 1)), $storeClass::COLLECTION_KEY);
        self::assertFalse($storeClass::CACHE_ROWS);
        self::assertFalse($storeClass::initDB(Objects::LAZY_STRATEGY_KEY)->cachesRows());
        $snapshot = $viewClass::initEmpty();
        $snapshot->add($item);
        self::assertSame(['73' => $row], $snapshot->toArray());
        self::assertSame($item, $snapshot[73]);
        self::assertSame($row, $snapshot->first()?->toArray());
    }

    /**
     * @param class-string<DbCollection> $viewClass Real journal View collection
     * @param array<string, mixed> $row Unused complete row from the shared provider
     */
    #[DataProvider('journalRows')]
    public function testRealJournalStoresRefuseUnboundedReadsBeforeSql(string $viewClass, array $row): void
    {
        $storeClass = $viewClass::OBJECT_COLLECTION_CLASS;
        $store = $storeClass::initDB(Objects::LAZY_STRATEGY_KEY);
        self::assertFalse($store->isAllLoaded());
        $view = $viewClass::init();
        $view->setObjectCollection($store);
        $this->expectException(UnboundedCollectionReadException::class);
        $view->queryPageItems(new TableQueryDTO());
    }

    /**
     * @param class-string<DbCollection> $viewClass Real journal View collection
     * @param array<string, mixed> $row Unused complete row from the shared provider
     */
    #[DataProvider('journalRows')]
    public function testUnsavedJournalObjectsHaveNoLogicalId(string $viewClass, array $row): void
    {
        $objectClass = ($viewClass::OBJECT_COLLECTION_CLASS)::OBJECT_CLASS;
        $this->expectException(ObjectGetIdStringNotImplementedException::class);
        $objectClass::create()->getIdString();
    }

    public function testRealTriggerWrittenEntitiesRefuseEveryWriteBeforeResolvingADatabase(): void
    {
        foreach (self::journalRows() as [$viewClass, $row]) {
            $entityClass = (($viewClass::OBJECT_COLLECTION_CLASS)::OBJECT_CLASS)::ENTITY_CLASS;
            if ($entityClass === ChangeLogReceipt::class) {
                continue;
            }
            foreach (['insert', 'update', 'diff', 'delete'] as $write) {
                $entity = $write === 'insert' ? $entityClass::getEmpty() : $entityClass::fromRow($row);
                try {
                    match ($write) {
                        'insert', 'update' => $entity->save(),
                        'diff' => $entity->saveDiff(clone $entity),
                        'delete' => $entity->delete(),
                    };
                    self::fail("{$entityClass} allowed {$write}");
                } catch (ExternalWriterException $failure) {
                    self::assertStringContainsString($entityClass::_table, $failure->getMessage());
                    self::assertStringContainsString('external database writer', $failure->getMessage());
                }
            }
        }
        self::assertSame([], new ReflectionProperty(Database::class, 'lastSql')->getValue());
        self::assertSame([], Database::getConfiguredIndices());
    }

    public function testKeyReadsObserveUnannouncedUpdateInsertAndDeleteWithoutRetainingRows(): void
    {
        $first = $this->store[1];
        CachelessEntity::$rows[1]['mark'] = 'changed';
        $second = $this->store[1];
        self::assertNotSame($first, $second);
        self::assertSame('first', $first?->toArray()['mark']);
        self::assertSame('changed', $second?->toArray()['mark']);
        self::assertNull($this->store[3]);
        CachelessEntity::$rows[3] = ['id' => 3, 'mark' => 'inserted'];
        self::assertSame('inserted', $this->store[3]?->toArray()['mark']);
        unset(CachelessEntity::$rows[1]);
        self::assertNull($this->store[1]);
        self::assertNull($this->store[null]);
        self::assertSame(5, CachelessEntity::$keyReads);
        $this->assertStoreEmpty();
    }

    public function testViewReadsKeepRequiredAndOptionalContractsAndReturnNewSnapshots(): void
    {
        $first = $this->view[1];
        $second = $this->view[1];
        self::assertNotSame($first, $second);
        self::assertNotSame($first->getObject(), $second->getObject());
        self::assertArrayNotHasKey(3, $this->view);
        self::assertNull($this->view[3] ?? null);
        self::assertNull($this->view[null] ?? null);
        CachelessEntity::$rows[3] = ['id' => 3, 'mark' => 'inserted'];
        self::assertArrayHasKey(3, $this->view);
        self::assertSame('inserted', $this->view[3]->toArray()['mark']);
        unset(CachelessEntity::$rows[1]);
        self::assertArrayNotHasKey(1, $this->view);
        self::assertSame('first', $first->toArray()['mark']);
        self::assertSame([], new ReflectionProperty(DbCollection::class, 'items')->getValue($this->view));
        $this->assertStoreEmpty();
        $this->expectException(ItemNotFoundException::class);
        $this->view[1];
    }

    public function testPageWrappersUseCurrentResultWithoutKeyQueriesOrReusingEarlierSnapshots(): void
    {
        $first = $this->view->queryPageItems(new TableQueryDTO(limit: 1));
        self::assertSame(['id' => 1, 'mark' => 'first'], $first['rows'][0]->toArray());
        self::assertSame(1, CachelessObject::$mappedRows, 'Frame neighbours do not become Objects');
        CachelessEntity::$rows[1]['mark'] = 'changed';
        $second = $this->view->queryPageItems(new TableQueryDTO(limit: 1));
        self::assertNotSame($first['rows'][0], $second['rows'][0]);
        self::assertNotSame($first['rows'][0]->getObject(), $second['rows'][0]->getObject());
        self::assertSame('first', $first['rows'][0]->toArray()['mark']);
        self::assertSame('changed', $second['rows'][0]->toArray()['mark']);
        self::assertSame(0, CachelessEntity::$keyReads, 'No per-row SELECT after the page query');
        self::assertSame([2, 2], CachelessEntity::$pageLimits);
        self::assertSame([TableConstants::COUNT_CEILING, TableConstants::COUNT_CEILING], CachelessEntity::$countCeilings);
        self::assertSame([], new ReflectionProperty(DbCollection::class, 'items')->getValue($this->view));
        $this->assertStoreEmpty();
    }

    public function testBoundedPageAndManualSelectionsCanBeWalkedAndKeepTheirSnapshot(): void
    {
        $page = $this->view->queryPageItems(new TableQueryDTO(limit: 2));
        $manual = CachelessCollection::initEmpty();
        $objects = CachelessObjects::initEmpty();
        foreach ($page['rows'] as $item) {
            $manual->add($item);
            $objects[$item->getIdString()] = $item->getObject();
        }
        CachelessEntity::$rows = [];
        self::assertSame([1, 2], array_keys(iterator_to_array($manual)));
        self::assertSame([1, 2], $objects->keys());
        self::assertSame('first', $manual->first()?->toArray()['mark']);
        self::assertSame('second', $objects->last()?->toArray()['mark']);
        self::assertCount(2, $objects->toArray());
        self::assertCount(2, $manual->toArray());
        self::assertTrue($objects->cachesRows());
        self::assertCount(0, $this->view);
        $this->assertStoreEmpty();
    }

    public function testExactCountsDoNotHydrateAndWindowCountsKeepTheirCeiling(): void
    {
        self::assertCount(2, $this->store);
        CachelessEntity::$rows[3] = ['id' => 3, 'mark' => 'third'];
        self::assertCount(3, $this->view);
        self::assertSame(1, $this->store->countByColumn('id', 3));
        self::assertSame(0, CachelessObject::$mappedRows);
        for ($id = 4; $id <= TableConstants::COUNT_CEILING + 3; $id++) {
            CachelessEntity::$rows[$id] = ['id' => $id, 'mark' => 'many'];
        }
        $page = $this->view->queryPage(new TableQueryDTO(limit: 1));
        self::assertFalse($page['totalExact']);
        self::assertSame(TableConstants::COUNT_CEILING, $page['totalCount']);
        self::assertSame(1, CachelessObject::$mappedRows);
        self::assertCount(TableConstants::COUNT_CEILING + 3, $this->store);
        $this->assertStoreEmpty();
    }

    /** @return iterable<string, array{int}> Strategies promising a whole set */
    public static function wholeStrategies(): iterable
    {
        yield 'eager' => [Objects::LAZY_STRATEGY_NONE];
        yield 'batch' => [Objects::LAZY_STRATEGY_BATCH];
        yield 'whole on access' => [Objects::LAZY_STRATEGY_FULL_ON_ACCESS];
    }

    /** @param int $strategy Whole-table strategy that cannot mount */
    #[DataProvider('wholeStrategies')]
    public function testMountRefusesWholeTableStrategies(int $strategy): void
    {
        $this->expectException(UnboundedCollectionReadException::class);
        $this->expectExceptionMessage('bounded query');
        CachelessObjects::initDB($strategy);
    }

    /** @return iterable<string, array{string}> All entrances that could silently answer with an incomplete set */
    public static function unboundedReads(): iterable
    {
        foreach (['loadAll', 'lazyLoadAll', 'preload', 'keys', 'walk', 'filter', 'first', 'last', 'array',
            'page', 'negative page', 'column', 'empty column', 'view walk', 'view filter', 'view array',
            'view first', 'view last', 'view page', 'where one', 'where null', 'where many', 'where empty'] as $read) {
            yield $read => [$read];
        }
    }

    /** @param string $read Unbounded read entrance */
    #[DataProvider('unboundedReads')]
    public function testWholeSetReadsRefuseBeforeQueryingOrChangingFullness(string $read): void
    {
        try {
            match ($read) {
                'loadAll' => $this->store->loadAllFromDB(),
                'lazyLoadAll' => $this->store->readAll(),
                'preload' => $this->store->preloadAll(),
                'keys' => $this->store->keys(),
                'walk' => iterator_to_array($this->store),
                'filter' => $this->store->filter(new ColumnFilter('id', FilterOperator::EQUALS, 1)),
                'first' => $this->store->first(),
                'last' => $this->store->last(),
                'array' => $this->store->toArray(),
                'page' => $this->store->queryPage(new TableQueryDTO()),
                'negative page' => $this->store->queryPage(new TableQueryDTO(limit: -2)),
                'column' => iterator_to_array($this->store->loadByColumn('id', 1)),
                'empty column' => iterator_to_array($this->store->loadByColumn('id')),
                'view walk' => iterator_to_array($this->view),
                'view filter' => $this->view->filter(static fn(DbItem $item): bool => true),
                'view array' => $this->view->toArray(),
                'view first' => $this->view->first(),
                'view last' => $this->view->last(),
                'view page' => $this->view->queryPageItems(new TableQueryDTO()),
                'where one' => $this->view->whereColumnIs('id', 1),
                'where null' => $this->view->whereColumnIs('id', null),
                'where many' => $this->view->whereColumnIn('id', 1, 2),
                'where empty' => $this->view->whereColumnIn('id'),
            };
            self::fail('The whole-set read must refuse');
        } catch (UnboundedCollectionReadException $failure) {
            self::assertStringContainsString(CachelessObjects::COLLECTION_KEY, $failure->getMessage());
            self::assertStringContainsString('positive limit', $failure->getMessage());
            self::assertSame(0, CachelessEntity::$keyReads);
            self::assertSame([], CachelessEntity::$pageLimits);
            $this->assertStoreEmpty();
        }
    }

    public function testSilentHydrationDoesNotKeepRowsOrAnnounceThem(): void
    {
        $this->store->readIntoStore(CachelessObject::fromEntity(CachelessEntity::fromRow(CachelessEntity::$rows[1])));
        $this->assertStoreEmpty();
        self::assertSame([], $this->changes->seen);
    }

    public function testSyncDoesNotFetchOrRetainRowsAndStillAnnouncesEveryForeignFact(): void
    {
        $snapshot = $this->view[1];
        CachelessEntity::$keyReads = 0;
        DbSyncApplicator::applyCreated(new DbSyncCreatedSignalData('cacheless', '8', ['id' => 8, 'mark' => 'local node']));
        DbSyncApplicator::applyCreated(
            new DbSyncCreatedSignalData('cacheless', '9', ['id' => 9, 'mark' => 'remote node']),
            originNodeId: 'another-node',
        );
        DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
            'cacheless', '1', ['mark' => 'changed'], 'browser', 'request', previous: ['mark' => 'first'],
        ));
        DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData('cacheless', '1', []));
        DbSyncApplicator::applyDeleted(new DbSyncDeletedSignalData('cacheless', '1', ['id' => 1, 'mark' => 'gone']));
        self::assertCount(5, $this->changes->seen);
        self::assertSame(array_fill(0, 5, SourceChangeProvenance::AppliedRemote), $this->changes->provenances);
        self::assertSame(['mark' => 'first'], $this->changes->seen[2]->previous);
        self::assertSame('browser', $this->changes->seen[2]->origin);
        self::assertSame('request', $this->changes->seen[2]->originRequestId);
        self::assertSame(['id' => 1, 'mark' => 'gone'], $this->changes->seen[4]->row);
        self::assertSame('first', $snapshot->toArray()['mark']);
        self::assertSame(0, CachelessEntity::$keyReads);
        $this->assertStoreEmpty();
    }

    public function testLocalOrmCreateStillQueuesSyncAndAnnouncesOnlyAfterCommit(): void
    {
        Database::configure(database: 'cacheless_primary');
        Database::configure(index: 2, database: 'cacheless_other');
        Database::useConnection(2);
        Database::transactionStart(index: 0);
        $object = CachelessObject::create();
        $object->sync();
        $this->store[$object->getIdString()] = $object;
        self::assertSame([], $this->changes->seen);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertStoreEmpty();
        Database::transactionCommit(index: 0);
        self::assertCount(1, $this->changes->seen);
        self::assertSame(SourceChangeProvenance::LocalWrite, $this->changes->provenances[0]);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(SignalConstants::DB_SYNC_CREATED, $signal?->signalType->getType());
        self::assertInstanceOf(DbSyncCreatedSignalData::class, $signal->data);
        DbSyncApplicator::applyCreated($signal->data);
        self::assertCount(1, $this->changes->seen, 'An own echo announces nothing twice');
        $this->assertStoreEmpty();
    }

    public function testLocalOrmUpdateStillAnnouncesAndQueuesItsDiffWithoutRetainingRows(): void
    {
        $snapshot = $this->view[1];
        $object = $this->store[1];
        self::assertInstanceOf(CachelessObject::class, $object);
        Database::transactionStart();
        $object->remark('updated locally');
        $object->sync();
        self::assertSame([], $this->changes->seen);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        Database::transactionCommit();
        self::assertCount(1, $this->changes->seen);
        self::assertSame(['mark' => 'updated locally'], $this->changes->seen[0]->row);
        self::assertSame(['mark' => 'first'], $this->changes->seen[0]->previous);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(SignalConstants::DB_SYNC_UPDATED, $signal?->signalType->getType());
        self::assertSame('first', $snapshot->toArray()['mark']);
        self::assertSame('updated locally', $this->view[1]->toArray()['mark']);
        $this->assertStoreEmpty();
    }

    public function testRollbackDropsLocalAnnouncementsAndNeverRestoresRowCache(): void
    {
        Database::transactionStart();
        $object = CachelessObject::create();
        $object->sync();
        $this->store[] = $object;
        Database::transactionRollback();
        self::assertSame([], $this->changes->seen);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertFalse($object->isRelated());
        $this->assertStoreEmpty();
    }

    public function testLocalRemovalAnnouncesWithoutFetchingADeletedRow(): void
    {
        unset($this->store[1]);
        self::assertCount(1, $this->changes->seen);
        self::assertSame('1', $this->changes->seen[0]->sourceId);
        self::assertSame(0, CachelessEntity::$keyReads);
        $this->assertStoreEmpty();
    }

    /** Confirms no positive, negative or completeness cache was created. */
    private function assertStoreEmpty(): void
    {
        self::assertSame([], $this->store->heldKeys());
        self::assertFalse($this->store->isAllLoaded());
        self::assertFalse($this->store->cachesRows());
    }
}

/** Deterministic Entity boundary; returns actual row values and honours the requested page bound. */
final class CachelessEntity extends Entity
{
    public const string _table = 'cacheless';
    public const string _primary = 'id';
    public const array _columns = ['id', 'mark'];
    public const array _types = ['id' => 'integer', 'mark' => 'string'];

    /** @var array<int, array{id: int, mark: string}> Storage changed without ORM announcements */
    public static array $rows = [];
    public static int $keyReads = 0;
    /** @var list<int> Row bounds requested by production paging */
    public static array $pageLimits = [];
    /** @var list<int> Ceilings requested by production window counts */
    public static array $countCeilings = [];

    public ?int $id = null;
    public string $mark = 'new';

    /**
     * @param mixed $id Requested primary key
     * @return ?static Fresh Entity or absence from current storage
     */
    public static function getById(mixed $id): ?static
    {
        self::$keyReads++;
        return isset(self::$rows[$id]) ? static::fromRow(self::$rows[$id]) : null;
    }

    /**
     * @param array<string, mixed>|string $filters Unused empty predicate in these page cases
     * @param array<int, mixed>|string $filtersParam Unused page parameters
     * @param array<string, string>|string $orderBy Primary-key order produced by production paging
     * @param int $limit Requested row bound, including frame neighbours
     * @param int $offset Offset of the page in current storage
     * @return EntityCollection Current bounded rows, materialized through normal fromRow()
     */
    public static function get(
        array|string $filters = [],
        array|string $filtersParam = [],
        array|string $orderBy = [],
        int $limit = TableConstants::NO_LIMIT,
        int $offset = 0,
    ): EntityCollection {
        self::$pageLimits[] = $limit;
        $entities = EntityCollection::empty();
        foreach (array_slice(self::$rows, $offset, $limit, true) as $key => $row) {
            $entities->add(static::fromRow($row), $key);
        }
        return $entities;
    }

    /**
     * @param array<string, mixed>|string $filters Empty predicate or one key equality
     * @param array<int, mixed>|string $filtersParam Unused parameters in these count cases
     * @return int Matching rows without materializing Entities or Objects
     */
    public static function count(array|string $filters = [], array|string $filtersParam = []): int
    {
        return is_array($filters) && isset($filters['id'])
            ? (int)isset(self::$rows[$filters['id']])
            : count(self::$rows);
    }

    /**
     * @param int $ceiling Production window count ceiling
     * @param array<string, mixed>|string $filters Empty predicate in these page cases
     * @param array<int, mixed>|string $filtersParam Unused page parameters
     * @return int Capped current row count without mapping rows
     */
    public static function countUpTo(int $ceiling, array|string $filters = [], array|string $filtersParam = []): int
    {
        self::$countCeilings[] = $ceiling;
        return min($ceiling + 1, static::count($filters, $filtersParam));
    }

    /**
     * @param list<string> $columns Unused write selection
     * @return bool Successful synthetic insert; ORM sync and its rollback hooks remain real
     */
    public function save(array $columns = []): bool
    {
        $this->id = 100;
        $this->flushRelated();
        return true;
    }

    /**
     * @param Entity $originalEntity Previous saved row, with production Object diffing still active
     * @return bool Successful synthetic update of this fixture's row values
     */
    public function saveDiff(Entity $originalEntity): bool
    {
        self::$rows[$this->id] = $this->toArray();
        $this->flushRelated();
        return true;
    }
}

/** Object mapping remains real; the counter observes how many window rows become Objects. */
final class CachelessObject extends Object_
{
    public const string ENTITY_CLASS = CachelessEntity::class;
    public const string OBJECT_COLLECTION_CLASS = CachelessObjects::class;

    public static int $mappedRows = 0;

    /**
     * @param Entity $entity Current storage row
     * @return static Fresh snapshot of that row
     */
    public static function fromEntity(Entity $entity): static
    {
        self::$mappedRows++;
        return parent::fromEntity($entity);
    }

    /** @param string $mark New value to save through the production Object sync path */
    public function remark(string $mark): void
    {
        $this->entity->mark = $mark;
    }
}

/** @extends Objects<CachelessObject> */
final class CachelessObjects extends Objects
{
    public const string OBJECT_CLASS = CachelessObject::class;
    public const string COLLECTION_KEY = 'cacheless';
    public const bool CACHE_ROWS = false;

    /** @return list<int|string> Stored keys without triggering a walk or load */
    public function heldKeys(): array
    {
        return array_keys($this->objects);
    }

    /** @param CachelessObject $object Snapshot passed through production silent hydration */
    public function readIntoStore(CachelessObject $object): void
    {
        $this->hydrate($object->getIdString(), $object);
    }

    /** Exposes the protected whole-load entrance for its refusal check. */
    public function readAll(): void
    {
        $this->lazyLoadAll();
    }
}

/** @extends DbCollection<CachelessItem, CachelessObjects> */
final class CachelessCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = CachelessItem::class;
    public const string OBJECT_COLLECTION_CLASS = CachelessObjects::class;
}

/** @extends DbItem<CachelessObject> */
final class CachelessItem extends DbItem
{
}

/** Fixture context exposes the same mount the sync applicator consults. */
final class CachelessContext extends HilosDbContext
{
    /**
     * @param CachelessObjects $objects Storage under test
     * @param CachelessCollection $view View over that storage
     * @return self Context with one mounted chain
     */
    public static function over(CachelessObjects $objects, CachelessCollection $view): self
    {
        $context = new self();
        $context->_objectCollections['cacheless'] = $objects;
        $context->_dbItemCollections['cacheless'] = $view;
        return $context;
    }

    /** No framework mounts are needed by this fixture. */
    public function configure(): void
    {
    }
}

/** Records delivered facts without changing their production routing or lifetime. */
final class CachelessChanges implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Delivered source facts */
    public array $seen = [];
    /** @var list<SourceChangeProvenance> Provenance of each delivered fact */
    public array $provenances = [];

    /**
     * @param SourceChange $change Fact delivered by the production bus
     * @param SourceChangeProvenance $provenance Origin preserved by the bus
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $change;
        $this->provenances[] = $provenance;
    }
}

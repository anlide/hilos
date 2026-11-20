<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Actions\Item\DbActions as ItemDbActions;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\Database\Exception\OrmForgetRefusedException;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\TableLagRuntime;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\Attributes\DataProvider;

/** Uses its own table to observe stale ORM rows independently of the write and sync doors. */
final class OrmForgetIntegrationTest extends FrameworkIntegrationTestCase
{
    private const int OTHER_INDEX = 2;

    private ?DbContext $previousDb = null;
    private ?RtContext $previousRt = null;
    private ?EnvAccessor $previousEnv = null;
    private OrmForgetContext $context;
    private OrmForgetChanges $changes;

    /**
     * Mounts a private table and a nonempty existing RT singleton as a control.
     *
     * @throws HilosException When connection, schema or fixture setup fails
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        $this->previousEnv = Hilos::$env;
        Database::sqlRun('DROP TABLE IF EXISTS `orm_forget_test`');
        Database::sqlRun('CREATE TABLE `orm_forget_test` (`id` INT PRIMARY KEY, `label` VARCHAR(40) NOT NULL) ENGINE=InnoDB');
        Database::sqlRun("INSERT INTO `orm_forget_test` VALUES (1, 'before'), (2, 'deleted')");
        $this->context = new OrmForgetContext();
        $this->context->configure();
        Hilos::$db = $this->context;
        Hilos::$rt = new class extends RtContext {
            /** The fixture mounts its existing singleton explicitly. */
            public function configure(): void
            {
            }
        };
        Hilos::$rt->mountFeatureItem(
            TableLagRuntime::RT_ITEM,
            TableLagRuntime::fromRow([TableLagRuntime::windowMs => 1563, TableLagRuntime::facetsMs => 17]),
        );
        $this->changes = new OrmForgetChanges();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe($this->changes);
    }

    /**
     * Closes a failing case's transaction before dropping the private table.
     *
     * @throws DatabaseException When fixture cleanup fails
     */
    protected function tearDown(): void
    {
        Database::handlerEnd();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::sqlRun('DROP TABLE IF EXISTS `orm_forget_test`');
        SourceChangeBus::reset();
        Hilos::$db = $this->previousDb;
        Hilos::$rt = $this->previousRt;
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    /**
     * @throws HilosException When a fixture read or reset fails
     */
    public function testResetIsColdAndNextReadReplacesStaleObjectsAndViews(): void
    {
        $objects = $this->context->getObjectCollection(OrmForgetObjects::COLLECTION_KEY);
        $view = $this->context->rows;
        $held = $view[1];
        $deleted = $view[2];
        $heldObject = $objects[1];
        $actions = $view->actions;
        $itemActions = $held->actions;
        $storeOnly = $this->context->getObjectCollection(OrmForgetContext::STORE_ONLY);
        $storeOnlyHeld = $storeOnly[1];
        $viewOnly = $this->context->viewOnly;
        $viewOnlyHeld = $viewOnly[1];
        $declarations = $this->context->getMountedEntities();
        $baseline = $this->context->generation();
        $runtime = Hilos::$rt;
        $runtimeRow = $runtime->hilosTableLagRuntime;
        $runtimeData = $runtimeRow->toArray();
        $configuration = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);

        Database::sqlRun("UPDATE `orm_forget_test` SET `label` = 'after' WHERE `id` = 1");
        Database::sqlRun('DELETE FROM `orm_forget_test` WHERE `id` = 2');
        Database::sqlRun("INSERT INTO `orm_forget_test` VALUES (3, 'new')");
        self::assertSame('before', $view[1]->label());
        self::assertSame($deleted, $view[2]);
        self::assertSame($heldObject, $objects[1]);
        self::assertSame('before', $objects[1]->label());
        $queries = self::questions();
        $result = Database::getCachedResultSet();
        $handle = Database::getCurrentResult();

        Hilos::$db->forgetLoadedDataForTest();

        self::assertSame($result, Database::getCachedResultSet());
        self::assertSame($handle, Database::getCurrentResult());
        self::assertSame($queries + 1, self::questions(), 'Only the measurement itself may issue SQL');
        self::assertSame([], $this->changes->seen);
        self::assertSame($this->context, Hilos::$db);
        self::assertSame($objects, $this->context->getObjectCollection(OrmForgetObjects::COLLECTION_KEY));
        self::assertSame($storeOnly, $this->context->getObjectCollection(OrmForgetContext::STORE_ONLY));
        self::assertSame($view, $this->context->rows);
        self::assertSame($viewOnly, $this->context->viewOnly);
        self::assertSame($actions, $view->actions);
        self::assertSame(OrmForgetItemActions::class, $view->getItemActionsClass());
        self::assertSame($declarations, $this->context->getMountedEntities());
        self::assertSame($baseline, $this->context->generation());
        self::assertSame($configuration, Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX));
        self::assertSame($runtime, Hilos::$rt);
        self::assertSame($runtimeData, Hilos::$rt->hilosTableLagRuntime->toArray());
        self::assertSame($runtimeData, $runtimeRow->toArray());
        self::assertFalse(isset($objects[1]));
        self::assertFalse(isset($storeOnly[1]), 'An object store without a view is reset too');

        $fresh = $view[1];
        self::assertNotSame($held, $fresh);
        self::assertNotSame($heldObject, $fresh->getObject());
        self::assertNotSame($itemActions, $fresh->actions);
        self::assertInstanceOf(OrmForgetItemActions::class, $fresh->actions);
        self::assertSame($objects[1], $fresh->getObject());
        self::assertSame('after', $fresh->label());
        self::assertArrayNotHasKey(2, $view);
        self::assertSame('new', $view[3]->label());
        self::assertNotSame($viewOnlyHeld, $viewOnly[1], 'A view-only registry entry loses its cached wrapper');
        self::assertSame('after', $viewOnly[1]->label());
        self::assertNotSame($storeOnlyHeld, $storeOnly[1]);
        self::assertSame('after', $storeOnly[1]->label());
        self::assertSame('before', $held->label(), 'Old external references are not rebound');
        self::assertSame('deleted', $deleted->label());
    }

    /**
     * @throws HilosException When a fixture read or reset fails
     */
    public function testForgettingOneHoldingReturnsOnlyTheNamedCollectionToItsMount(): void
    {
        $objects = $this->context->getObjectCollection(OrmForgetObjects::COLLECTION_KEY);
        $storeOnly = $this->context->getObjectCollection(OrmForgetContext::STORE_ONLY);
        $objects->preloadAll();
        $storeOnly->preloadAll();
        $held = $this->context->rows[1];
        $storeOnlyHeld = $storeOnly[1];
        Database::sqlRun("UPDATE `orm_forget_test` SET `label` = 'after' WHERE `id` = 1");
        self::assertTrue($objects->isAllLoaded());
        self::assertSame('before', $this->context->rows[1]->label());
        $queries = self::questions();

        Hilos::$db->forgetHoldingForTest(OrmForgetObjects::COLLECTION_KEY);

        self::assertSame($queries + 1, self::questions(), 'Only the measurement itself may issue SQL');
        self::assertSame([], $this->changes->seen);
        self::assertSame($objects, $this->context->getObjectCollection(OrmForgetObjects::COLLECTION_KEY));
        self::assertFalse($objects->isAllLoaded());
        self::assertFalse(isset($objects[1]));
        try {
            iterator_to_array($objects);
            self::fail('A collection that no longer holds the set must refuse a walk');
        } catch (CollectionNotFullyLoadedException) {
            self::assertFalse($objects->isAllLoaded());
        }
        $fresh = $this->context->rows[1];
        self::assertNotSame($held, $fresh);
        self::assertSame('after', $fresh->label());
        self::assertSame('before', $held->label(), 'Old external references are not rebound');
        self::assertFalse($objects->isAllLoaded(), 'A read by key does not make the set whole');
        self::assertSame($storeOnly, $this->context->getObjectCollection(OrmForgetContext::STORE_ONLY));
        self::assertTrue($storeOnly->isAllLoaded());
        self::assertSame($storeOnlyHeld, $storeOnly[1]);

        Hilos::$db->forgetHoldingForTest(OrmForgetObjects::COLLECTION_KEY, OrmForgetObjects::COLLECTION_KEY);

        self::assertFalse($objects->isAllLoaded(), 'A repeated key and a repeated call end in the same state');
        self::assertFalse(isset($objects[1]));
        self::assertTrue($storeOnly->isAllLoaded());
        self::assertSame($storeOnlyHeld, $storeOnly[1]);
    }

    /**
     * @throws HilosException When a fixture read or reset fails
     */
    public function testForgettingEveryHoldingReturnsTheWholeContextToItsMount(): void
    {
        $objects = $this->context->getObjectCollection(OrmForgetObjects::COLLECTION_KEY);
        $storeOnly = $this->context->getObjectCollection(OrmForgetContext::STORE_ONLY);
        $objects->preloadAll();
        $storeOnly->preloadAll();
        $held = $this->context->rows[1];
        $viewOnlyHeld = $this->context->viewOnly[1];
        $queries = self::questions();

        Hilos::$db->forgetHoldingForTest();

        self::assertSame($queries + 1, self::questions(), 'Only the measurement itself may issue SQL');
        self::assertSame([], $this->changes->seen);
        foreach ([$objects, $storeOnly] as $collection) {
            self::assertFalse($collection->isAllLoaded());
            self::assertFalse(isset($collection[1]));
            try {
                iterator_to_array($collection);
                self::fail('Every mounted collection must be partial again');
            } catch (CollectionNotFullyLoadedException) {
                self::assertFalse($collection->isAllLoaded());
            }
        }
        self::assertNotSame($held, $this->context->rows[1]);
        self::assertNotSame($viewOnlyHeld, $this->context->viewOnly[1], 'A view-only registry entry loses its cached wrapper');
    }

    /**
     * @throws HilosException When a fixture read or reset fails
     */
    public function testAnUnmountedKeyRefusesBeforeAnyNamedCollectionChanges(): void
    {
        $objects = $this->context->getObjectCollection(OrmForgetObjects::COLLECTION_KEY);
        $objects->preloadAll();
        $held = $this->context->rows[1];
        $heldObject = $objects[1];

        try {
            Hilos::$db->forgetHoldingForTest(OrmForgetObjects::COLLECTION_KEY, 'missing');
            self::fail('An unmounted key must refuse the whole call');
        } catch (ObjectCollectionNotFoundException $failure) {
            self::assertSame('Object collection [missing] is not mounted', $failure->getMessage());
        }

        self::assertTrue($objects->isAllLoaded());
        self::assertSame($heldObject, $objects[1]);
        self::assertSame($held, $this->context->rows[1]);
    }

    /**
     * @return iterable<string, array{Closure(DbContext): void}> Both test-only forget entrances of the context
     */
    public static function contextEntrances(): iterable
    {
        yield 'loaded data' => [static fn (DbContext $db) => $db->forgetLoadedDataForTest()];
        yield 'holding' => [static fn (DbContext $db) => $db->forgetHoldingForTest()];
    }

    /**
     * @return iterable<string, array{int, bool, Closure(DbContext): void}> Transaction index, whether it commits, and the entrance
     */
    public static function transactionCases(): iterable
    {
        foreach (self::contextEntrances() as $entrance => [$forget]) {
            yield 'current rollback, ' . $entrance => [DatabaseConnectionDefaults::PRIMARY_INDEX, false, $forget];
            yield 'current commit, ' . $entrance => [DatabaseConnectionDefaults::PRIMARY_INDEX, true, $forget];
            yield 'other rollback, ' . $entrance => [self::OTHER_INDEX, false, $forget];
            yield 'other commit, ' . $entrance => [self::OTHER_INDEX, true, $forget];
        }
    }

    /**
     * @param int $index Connection holding the transaction
     * @param bool $commit Whether the transaction commits after the refused reset
     * @param Closure(DbContext): void $forget Entrance under test
     * @throws HilosException When transaction or fixture access fails
     */
    #[DataProvider('transactionCases')]
    public function testAnyOpenTransactionRefusesBeforeClearingAnything(int $index, bool $commit, Closure $forget): void
    {
        $view = $this->context->rows;
        $held = $view[1];
        $object = $held->getObject();
        $storeOnly = $this->context->getObjectCollection(OrmForgetContext::STORE_ONLY);
        $storeOnlyHeld = $storeOnly[1];
        $viewOnlyHeld = $this->context->viewOnly[1];
        if ($index !== DatabaseConnectionDefaults::PRIMARY_INDEX) {
            $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::configure($index, $config->host, $config->user, $config->password, $config->database, $config->port, $config->charset);
        }
        Database::useConnection($index);
        $announced = 0;
        $undone = 0;
        Database::transactionStart();
        Database::sqlRun("UPDATE `orm_forget_test` SET `label` = 'transaction' WHERE `id` = 1");
        Database::afterCommit(static function () use (&$announced): void {
            $announced++;
        });
        Database::onRollback(static function () use (&$undone): void {
            $undone++;
        });
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        self::assertTrue(Database::hasOpenTransactions());
        $queries = self::questions();
        try {
            $forget(Hilos::$db);
            self::fail('A transaction on any index must refuse the reset');
        } catch (OrmForgetRefusedException $failure) {
            self::assertStringContainsString('transaction is open', $failure->getMessage());
        }
        self::assertSame($queries + 1, self::questions());
        self::assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
        self::assertTrue(Database::hasOpenTransactions());
        self::assertSame($held, $view[1]);
        self::assertSame($object, $view->getObjectCollection()[1]);
        self::assertSame($storeOnlyHeld, $storeOnly[1]);
        self::assertSame($viewOnlyHeld, $this->context->viewOnly[1]);
        self::assertSame(0, $announced);
        self::assertSame(0, $undone);

        Database::useConnection($index);
        if ($commit) {
            Database::transactionCommit();
        } else {
            Database::transactionRollback();
        }
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame($commit ? 1 : 0, $announced);
        self::assertSame($commit ? 0 : 1, $undone);
        $queries = self::questions();
        $forget(Hilos::$db);
        self::assertSame($queries + 1, self::questions());
        self::assertSame($commit ? 'transaction' : 'before', $view[1]->label());
        self::assertNotSame($held, $view[1]);
    }

    /**
     * @param Closure(DbContext): void $forget Entrance under test
     * @throws HilosException When reading a fixture fails
     */
    #[DataProvider('contextEntrances')]
    public function testContextRefusesAnUnreadableEnvironmentBeforeClearingAnyRegistry(Closure $forget): void
    {
        $held = $this->context->rows[1];
        $storeOnly = $this->context->getObjectCollection(OrmForgetContext::STORE_ONLY);
        $storeOnlyHeld = $storeOnly[1];
        $viewOnlyHeld = $this->context->viewOnly[1];
        Hilos::$env = null;
        $queries = self::questions();
        try {
            $forget(Hilos::$db);
            self::fail('An unreadable environment must refuse the reset');
        } catch (OrmForgetRefusedException $failure) {
            self::assertStringContainsString('non-production environment', $failure->getMessage());
        }
        self::assertSame($queries + 1, self::questions());
        self::assertSame($held, $this->context->rows[1]);
        self::assertSame($storeOnlyHeld, $storeOnly[1]);
        self::assertSame($viewOnlyHeld, $this->context->viewOnly[1]);
    }

    /**
     * Counts every statement, not only SELECT; each measurement adds its own SHOW.
     *
     * @return int Session's statement counter
     * @throws DatabaseException When the counter cannot be read
     */
    private static function questions(): int
    {
        return (int)Database::sql("SHOW SESSION STATUS LIKE 'Questions'")->firstRow()['Value'];
    }
}

/** Private integration table; no framework schema or migration changes. */
final class OrmForgetEntity extends Entity
{
    public const string _table = 'orm_forget_test';
    public const string _primary = 'id';
    public const array _columns = ['id', 'label'];
    public const array _types = ['id' => 'integer', 'label' => 'string'];

    public ?int $id = null;
    public string $label = '';
}

/** Uses the real SQL loaders. */
final class OrmForgetEntities extends EntityCollection
{
    public const string ENTITY_CLASS = OrmForgetEntity::class;
}

/** @property OrmForgetEntity $entity Private table row */
final class OrmForgetObject extends Object_
{
    public const string ENTITY_CLASS = OrmForgetEntity::class;

    /** @return string Label of the loaded row */
    public function label(): string
    {
        return $this->entity->label;
    }
}

/** Real key loader over the private table. */
final class OrmForgetObjects extends Objects
{
    public const string COLLECTION_KEY = 'rows';
    public const string OBJECT_CLASS = OrmForgetObject::class;
    public const string ENTITY_COLLECTION_CLASS = OrmForgetEntities::class;
}

/** @extends DbItem<OrmForgetObject> */
final class OrmForgetItem extends DbItem
{
    /** @return string Label of the object this wrapper holds */
    public function label(): string
    {
        return $this->_object->label();
    }
}

/** @extends DbCollection<OrmForgetItem, OrmForgetObjects> */
final class OrmForgetView extends DbCollection
{
    public const string DB_ITEM_CLASS = OrmForgetItem::class;
    public const string OBJECT_COLLECTION_CLASS = OrmForgetObjects::class;
}

/** Collection action declaration whose cached instance must survive. */
final class OrmForgetActions extends DbActions
{
}

/** Item action declaration which new wrappers must retain. */
final class OrmForgetItemActions extends ItemDbActions
{
}

/**
 * @property-read OrmForgetView $rows View of the mounted table
 * @property-read OrmForgetView $viewOnly Additional wrapper cache without its own object registry entry
 */
final class OrmForgetContext extends HilosDbContext
{
    public const string STORE_ONLY = 'storeOnly';
    private const string VIEW_ONLY = 'viewOnly';

    /**
     * Deliberately asymmetric registries exercise both complete sweeps.
     *
     * @throws HilosException When fixture representation fails
     */
    public function configure(): void
    {
        $this->mountObjectCollection(OrmForgetObjects::class, Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(OrmForgetObjects::COLLECTION_KEY, OrmForgetView::class, OrmForgetActions::class, OrmForgetItemActions::class);
        $this->_objectCollections[self::STORE_ONLY] = OrmForgetObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_dbItemCollections[self::VIEW_ONLY] = OrmForgetView::init();
        $this->_dbItemCollections[self::VIEW_ONLY]->setObjectCollection($this->_objectCollections[OrmForgetObjects::COLLECTION_KEY]);
        $this->_dbGeneration = 'baseline-before-forget';
    }

    /** @return ?string Generation baseline without sampling the database */
    public function generation(): ?string
    {
        return $this->_dbGeneration;
    }
}

/** Records every source announcement during the case. */
final class OrmForgetChanges implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Announced changes */
    public array $seen = [];

    /**
     * @param SourceChange $change Announced change
     * @param SourceChangeProvenance $provenance Origin of the announcement
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $change;
    }
}

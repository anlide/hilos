<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The hole HIL-716 closed: the right to write was asked inside a switch over the lazy-loading
 * strategy, so only a collection loaded whole was ever guarded. Every hot table is lazy, and
 * every one of them was written by anybody in silence.
 *
 * These cases stand on the three LAZY strategies for that reason - they are the branches that
 * used to be a bare `break` - and they stop at the door, which is the last point before a
 * write reaches the database: refusing there costs no connection, so the cases need none.
 */
final class DbWriteGuardLazyCollectionsTest extends TestCase
{
    private const string COLLECTION = 'unit_guard_lazy';
    private const string AGENT = 'unit_guard_agent';
    private const string OWN_SET = '42';
    private const string FOREIGN_SET = '7';

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::AGENT);

        parent::tearDown();
    }

    /**
     * @return list<array{int}> One case per lazy strategy, by its own name
     */
    public static function lazyStrategies(): array
    {
        return [
            'key' => [Objects::LAZY_STRATEGY_KEY],
            'batch' => [Objects::LAZY_STRATEGY_BATCH],
            'full on access' => [Objects::LAZY_STRATEGY_FULL_ON_ACCESS],
        ];
    }

    /**
     * @param int $strategy Lazy-loading strategy the collection is registered with
     */
    #[DataProvider('lazyStrategies')]
    public function testLazyCollectionRefusesAWriteNobodyClaimed(int $strategy): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, $strategy);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(WriteNotAllowedException::class);
        $actions->writePublic();
    }

    /**
     * @param int $strategy Lazy-loading strategy the collection is registered with
     */
    #[DataProvider('lazyStrategies')]
    public function testLazyCollectionAllowsTheAgentThatClaimedIt(int $strategy): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, $strategy);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::all(), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->writePublic();

        $this->assertTrue(TruthSourceRegistry::hasTruthSource(self::COLLECTION));
    }

    public function testCreatingIsJudgedApartFromEditing(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        TruthSourceRegistry::registerCreate(self::COLLECTION, self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        // A grant of zero width may mint a row and owns none, so editing is still refused.
        $actions->createPublic();

        $this->expectException(WriteNotAllowedException::class);
        $actions->writePublic();
    }

    public function testEditingRightDoesNotByItselfAllowCreating(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::listed('1'),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(CreateNotAllowedException::class);
        $actions->createPublic();
    }

    public function testDeleteAllIsJudgedOverTheWholeCollection(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        // Owning one row is not owning the table, and a truncate names no row at all.
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::listed('1'), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(WriteNotAllowedException::class);
        $actions->deleteAllPublic();
    }

    public function testDeleteAllAsksForRemoveBeforeReachingTheTable(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        // The whole table is claimed, but without the right to drop rows: the truncate stops at the door.
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::all(),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("may not remove rows across the whole table");
        $actions->deleteAllPublic();
    }

    public function testCollectionWriteDoorAsksForTheOperationItWasNamed(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::all(),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->removePublic();

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("may not update");
        $actions->writePublic();
    }

    /**
     * @param int $strategy Lazy-loading strategy the collection is registered with
     */
    #[DataProvider('lazyStrategies')]
    public function testSetDoorLetsTheSetOwnerWriteItsSetAndRefusesAnother(int $strategy): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, $strategy);
        TruthSourceRegistry::register(self::COLLECTION, TruthSourceKeys::set(self::OWN_SET), self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->writeSetPublic(self::OWN_SET);
        $actions->removeSetPublic(self::OWN_SET);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("set '7': it holds set '42'.");
        $actions->removeSetPublic(self::FOREIGN_SET);
    }

    public function testSetDoorAsksForTheOperationItWasNamed(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::set(self::OWN_SET),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Remove),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->removeSetPublic(self::OWN_SET);

        $this->expectException(WriteNotAllowedException::class);
        $this->expectExceptionMessage("may not update rows of set '42'");
        $actions->writeSetPublic(self::OWN_SET);
    }

    public function testManualCollectionWithNoKeyIsNotJudged(): void
    {
        $actions = $this->actionsFor(UnkeyedObjects::class, Objects::LAZY_STRATEGY_KEY);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->writePublic();
        $actions->createPublic();
        $actions->writeSetPublic(self::OWN_SET);

        $this->assertFalse(TruthSourceRegistry::hasTruthSource(''));
    }

    /**
     * Builds the collection door of a fixture collection on one lazy strategy.
     *
     * @param class-string<Objects> $objectsClass Object-collection fixture to stand the door on
     * @param int $strategy Lazy-loading strategy the collection is registered with
     * @return GuardedDbActions The door, ready to be asked
     */
    private function actionsFor(string $objectsClass, int $strategy): GuardedDbActions
    {
        $objectCollection = $objectsClass::initDB($strategy);
        $dbCollection = GuardedDbCollection::init();
        $dbCollection->setObjectCollection($objectCollection);
        $dbCollection->setActionsClass(GuardedDbActions::class);

        return $dbCollection->actions;
    }
}

/**
 * Minimal entity fixture for the guard cases, cut into sets by its owner column.
 *
 * The set door climbs the value it is handed by the table's set column (HIL-1111), so a table
 * that declared none would be in nobody's set and refuse every claim over one. The column is a
 * soft reference: the value is the top as it is.
 */
final class GuardedEntity extends Entity
{
    public const string _table = 'guard_lazy_test';
    public const string _primary = 'id';
    public const array _columns = ['id', 'owner_id'];
    public const array _types = ['id' => 'integer', 'owner_id' => 'integer'];
    public const string _setVia = 'owner_id';
    public const bool _setRoot = false;

    public ?int $id = null;
    public ?int $owner_id = null;
}

/**
 * Minimal object fixture wrapping the guard entity.
 */
final class GuardedObject extends Object_
{
    public const string ENTITY_CLASS = GuardedEntity::class;
}

/**
 * A named collection: the kind the guard judges.
 */
final class GuardedObjects extends Objects
{
    public const string OBJECT_CLASS = GuardedObject::class;
    public const string COLLECTION_KEY = 'unit_guard_lazy';
}

/**
 * A manual collection: no key, so no owner to ask for.
 */
final class UnkeyedObjects extends Objects
{
    public const string OBJECT_CLASS = GuardedObject::class;
}

/**
 * Minimal DB collection fixture holding the guard's object collection.
 */
final class GuardedDbCollection extends DbCollection
{
}

/**
 * Exposes the protected doors the guard stands in, the write door once per operation it is asked with.
 */
final class GuardedDbActions extends DbActions
{
    public function writePublic(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);
    }

    public function removePublic(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
    }

    /**
     * Asks the set door for a bulk edit of one set.
     *
     * @param string $setKey Set the edit is cut by
     * @throws WriteNotAllowedException When the truth source rejects the edit of that set
     */
    public function writeSetPublic(string $setKey): void
    {
        $this->ensureCanWriteSet($setKey, TruthSourceOperation::Update);
    }

    /**
     * Asks the set door for a delete of one set.
     *
     * @param string $setKey Set the delete is cut by
     * @throws WriteNotAllowedException When the truth source rejects the delete of that set
     */
    public function removeSetPublic(string $setKey): void
    {
        $this->ensureCanWriteSet($setKey, TruthSourceOperation::Remove);
    }

    public function createPublic(): void
    {
        $this->ensureCanCreate();
    }

    public function deleteAllPublic(): void
    {
        $this->deleteAllObjects();
    }
}

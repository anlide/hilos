<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
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
        TruthSourceRegistry::register(self::COLLECTION, true, self::AGENT);
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
        TruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT, [TruthSourceOperation::Update]);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(CreateNotAllowedException::class);
        $actions->createPublic();
    }

    public function testDeleteAllIsJudgedOverTheWholeCollection(): void
    {
        $actions = $this->actionsFor(GuardedObjects::class, Objects::LAZY_STRATEGY_KEY);
        // Owning one row is not owning the table, and a truncate names no row at all.
        TruthSourceRegistry::register(self::COLLECTION, ['1'], self::AGENT);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $this->expectException(WriteNotAllowedException::class);
        $actions->deleteAllPublic();
    }

    public function testManualCollectionWithNoKeyIsNotJudged(): void
    {
        $actions = $this->actionsFor(UnkeyedObjects::class, Objects::LAZY_STRATEGY_KEY);
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->writePublic();
        $actions->createPublic();

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
 * Minimal single-column entity fixture for the guard cases.
 */
final class GuardedEntity extends Entity
{
    public const string _table = 'guard_lazy_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;
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
 * Exposes the three protected doors the guard stands in.
 */
final class GuardedDbActions extends DbActions
{
    public function writePublic(): void
    {
        $this->ensureCanWrite();
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

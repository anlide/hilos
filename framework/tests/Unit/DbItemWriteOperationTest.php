<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use PHPUnit\Framework\TestCase;

/**
 * HIL-978: DB item actions ask the truth source about the operation being performed.
 *
 * Defaults to Update for compatibility with existing editing actions, while delete()
 * calls pass TruthSourceOperation::Remove explicitly.
 */
final class DbItemWriteOperationTest extends TestCase
{
    private const string COLLECTION = 'unit_guard_item';
    private const string AGENT = 'unit_guard_agent';

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::AGENT);

        parent::tearDown();
    }

    public function testOwnerWithAddAndRemoveAllowsDeleteAndRefusesWrite(): void
    {
        $actions = $this->actionsFor();
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::listed('1'),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->deletePublic();

        $this->expectException(WriteNotAllowedException::class);
        $actions->writePublic();
    }

    public function testOwnerWithAddAndUpdateAllowsWriteAndRefusesDeleteNamingRemove(): void
    {
        $actions = $this->actionsFor();
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::listed('1'),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->writePublic();

        try {
            $actions->deletePublic();
            $this->fail('Expected WriteNotAllowedException was not thrown on delete');
        } catch (WriteNotAllowedException $e) {
            $this->assertStringContainsString('may not remove item', $e->getMessage());
        }
    }

    public function testOwnerWithUpdateOnlyAllowsWriteByDefaultForExistingCallers(): void
    {
        $actions = $this->actionsFor();
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::listed('1'),
            self::AGENT,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->writePublic();

        $this->assertTrue(TruthSourceRegistry::hasTruthSource(self::COLLECTION));
    }

    public function testOwnerWithAllOperationsAllowsBothDoors(): void
    {
        $actions = $this->actionsFor();
        TruthSourceRegistry::register(
            self::COLLECTION,
            TruthSourceKeys::listed('1'),
            self::AGENT,
            TruthSourceOperations::of(...TruthSourceOperation::ALL),
        );
        ExecutionContext::setCurrentAgentId(self::AGENT);

        $actions->writePublic();
        $actions->deletePublic();

        $this->assertTrue(TruthSourceRegistry::hasTruthSource(self::COLLECTION));
    }

    /**
     * Builds item actions on top of a fixture object and collection.
     *
     * @return ItemGuardedDbActions The item actions door, ready to be tested
     */
    private function actionsFor(): ItemGuardedDbActions
    {
        $objectCollection = ItemGuardedObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $dbCollection = ItemGuardedDbCollection::init();
        $dbCollection->setObjectCollection($objectCollection);
        $dbCollection->setItemActionsClass(ItemGuardedDbActions::class);

        $entity = new ItemGuardedEntity();
        $entity->id = 1;
        $entity->flushRelated();

        $object = ItemGuardedObject::fromEntity($entity);
        $item = $dbCollection->createItemPublic($object);

        /** @var ItemGuardedDbActions */
        return $item->actions;
    }
}

/**
 * Minimal single-column entity fixture for item write guard test.
 */
final class ItemGuardedEntity extends Entity
{
    public const string _table = 'guard_item_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;
}

/**
 * Minimal object fixture wrapping the entity.
 */
final class ItemGuardedObject extends Object_
{
    public const string ENTITY_CLASS = ItemGuardedEntity::class;
}

/**
 * Objects collection fixture declaring item and collection classes.
 */
final class ItemGuardedObjects extends Objects
{
    public const string OBJECT_CLASS = ItemGuardedObject::class;
    public const string COLLECTION_KEY = 'unit_guard_item';
    public const string DB_ITEM_CLASS = ItemGuardedDbItem::class;
    public const string OBJECT_COLLECTION_CLASS = self::class;
}

/**
 * Minimal DbItem fixture for item write guard test.
 */
final class ItemGuardedDbItem extends DbItem
{
}

/**
 * DbCollection fixture opening createDbItem() to public for testing.
 */
final class ItemGuardedDbCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = ItemGuardedDbItem::class;
    public const string OBJECT_COLLECTION_CLASS = ItemGuardedObjects::class;

    /**
     * Creates DbItem for testing.
     *
     * @param Object_ $object Object instance to wrap
     * @return ItemGuardedDbItem Item view wrapping the object
     */
    public function createItemPublic(Object_ $object): ItemGuardedDbItem
    {
        /** @var ItemGuardedDbItem */
        return $this->createDbItem($object);
    }
}

/**
 * Exposes the two item write doors (default update and explicit remove) to public.
 */
final class ItemGuardedDbActions extends DbActions
{
    /**
     * Exposes ensureCanWrite() using the default operation (Update).
     *
     * @throws WriteNotAllowedException When the truth source rejects update
     */
    public function writePublic(): void
    {
        $this->ensureCanWrite();
    }

    /**
     * Exposes ensureCanWrite() using TruthSourceOperation::Remove.
     *
     * @throws WriteNotAllowedException When the truth source rejects remove
     */
    public function deletePublic(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
    }
}

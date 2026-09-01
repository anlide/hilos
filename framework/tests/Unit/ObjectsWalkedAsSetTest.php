<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\Database\Filter\ColumnFilter;
use Hilos\Database\Filter\FilterOperator;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;
use PHPUnit\Framework\TestCase;

/**
 * Walking a collection that holds part of a set is refused by name (HIL-781).
 *
 * The refusal exists because the two answers were indistinguishable: a key-lazy collection walked
 * as a set answers with the rows somebody fetched by key, and that reads exactly like "these are
 * all of them". What makes this delicate is that completeness is one answer per strategy and not
 * one bit - the batch strategy becomes complete BY being walked, and it is the default, so a gate
 * that only asked isAllLoaded() would refuse most of the repository.
 *
 * Hence one case per strategy: two refusals and two silences.
 */
final class ObjectsWalkedAsSetTest extends TestCase
{
    /**
     * @throws HilosException When the collection refuses the walk
     */
    public function testTheEagerStrategyIsNeverRefused(): void
    {
        $collection = WalkedAsSetObjects::withStrategy(Objects::LAZY_STRATEGY_NONE);

        $this->assertSame([], $collection->keys());
    }

    /**
     * The walk IS the load here, so refusing it would refuse the collection its own way of
     * becoming complete - and this is what initDB() hands out unless asked otherwise.
     *
     * @throws HilosException When the collection refuses the walk
     */
    public function testTheBatchStrategyIsNeverRefusedEither(): void
    {
        $collection = WalkedAsSetObjects::withStrategy(Objects::LAZY_STRATEGY_BATCH);

        $this->assertSame([1, 2], $collection->keys());
    }

    /**
     * @throws HilosException When the collection refuses the walk
     */
    public function testTheKeyStrategyIsRefusedUntilSomebodyDeclaredItComplete(): void
    {
        $collection = WalkedAsSetObjects::withStrategy(Objects::LAZY_STRATEGY_KEY);

        $this->expectException(CollectionNotFullyLoadedException::class);
        $this->expectExceptionMessage(
            "database collection 'walked_as_set' is walked as a set but declared no completeness"
        );

        $collection->keys();
    }

    /**
     * Iteration loads nothing under this strategy - only a read by key does - so a walk is as
     * partial here as it is under the key strategy.
     *
     * @throws HilosException When the collection refuses the walk
     */
    public function testTheLoadOnAccessStrategyIsRefusedTheSameWay(): void
    {
        $collection = WalkedAsSetObjects::withStrategy(Objects::LAZY_STRATEGY_FULL_ON_ACCESS);

        $this->expectException(CollectionNotFullyLoadedException::class);

        $collection->keys();
    }

    /**
     * @throws HilosException When the collection refuses the walk
     */
    public function testAHolderThatDeclaredCompletenessWalksItsOwnSet(): void
    {
        $collection = WalkedAsSetObjects::withStrategy(Objects::LAZY_STRATEGY_KEY);
        $collection->preloadAll();

        $this->assertSame([1, 2], $collection->keys());
    }

    /**
     * The batch strategy loads instead of refusing here too, and that is not a detail: before the
     * refusal existed, filtering an unloaded batch collection threw. Answering it with an empty
     * result instead would be the very defect this refusal is against, wearing a quieter face.
     *
     * @throws HilosException When the collection refuses the filter
     */
    public function testFilteringABatchCollectionLoadsItRatherThanAnsweringWithNothing(): void
    {
        $collection = WalkedAsSetObjects::withStrategy(Objects::LAZY_STRATEGY_BATCH);

        $filtered = $collection->filter(new ColumnFilter('id', FilterOperator::EQUALS, 2));

        $this->assertSame([2], $filtered->keys());
    }

    /**
     * Filtering is a walk with a predicate, and it answers for the same completeness - including
     * on the branches that used to filter memory unguarded because a truth source was registered.
     *
     * @throws HilosException When the collection refuses the filter
     */
    public function testFilteringIsRefusedOnTheSameCondition(): void
    {
        $collection = WalkedAsSetObjects::withStrategy(Objects::LAZY_STRATEGY_KEY);

        $this->expectException(CollectionNotFullyLoadedException::class);
        $this->expectExceptionMessage(
            "database collection 'walked_as_set' is walked as a set but declared no completeness"
        );

        $collection->filter(new ColumnFilter('id', FilterOperator::EQUALS, 1));
    }
}

/**
 * Entity fixture whose table is two rows held in the test.
 */
final class WalkedAsSetEntity extends Entity
{
    public const string _table = 'walked_as_set_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;
}

/**
 * Entity collection fixture answering with the fake table.
 */
final class WalkedAsSetEntities extends EntityCollection
{
    public const string ENTITY_CLASS = WalkedAsSetEntity::class;

    /**
     * @return static The two rows the fake table holds
     */
    public static function initFullDB(): static
    {
        $collection = new static();
        foreach ([1, 2] as $id) {
            $entity = new WalkedAsSetEntity();
            $entity->id = $id;
            $entity->flushRelated();
            $collection->add($entity, (string) $id);
        }

        return $collection;
    }
}

/**
 * Object fixture wrapping the walked entity.
 */
final class WalkedAsSetObject extends Object_
{
    public const string ENTITY_CLASS = WalkedAsSetEntity::class;
    public const string id = 'id';

    /**
     * @param string $property Property name (id)
     * @return mixed Property value
     * @throws HilosException When the property is no field of this fixture
     */
    public function __get(string $property): mixed
    {
        return $property === self::id ? $this->entity->id : parent::__get($property);
    }
}

/**
 * Object collection fixture, built under whichever strategy a case is about.
 */
final class WalkedAsSetObjects extends Objects
{
    public const string OBJECT_CLASS = WalkedAsSetObject::class;
    public const string ENTITY_COLLECTION_CLASS = WalkedAsSetEntities::class;
    public const string COLLECTION_KEY = 'walked_as_set';

    /**
     * @param int $strategy Lazy loading strategy the case is about
     * @return self Collection built under that strategy
     * @throws HilosException When the collection refuses to be built
     */
    public static function withStrategy(int $strategy): self
    {
        return self::initDB($strategy);
    }
}

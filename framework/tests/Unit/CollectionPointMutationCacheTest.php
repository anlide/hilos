<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\OutboundRtSyncSubscriber;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Exception\CollectionNotFoundException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\View\Collection\DirectUnsetException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Collection\RtCollectionDirectUnsetException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the item cache of a collection under point mutations (HIL-587).
 *
 * A collection answers reads out of a cache of wrappers, and until now only mass operations
 * ever emptied it: a point add or remove changed the store, queued the sync, and left the
 * cache holding whatever it held before. So a removed key kept answering as alive, and a
 * key that came back answered with the wrapper around the state it no longer holds.
 *
 * What is pinned here is that the point mutations drop exactly the one key they touched,
 * and that they drop it without resetting the iterator - the walk that drove the mutation
 * has to outlive it, which a full cache reset would not allow.
 *
 * Since HIL-603 the drop is no longer done by the actions that mutate: the collection announces
 * the change and a subscriber repairs the view, addressing it through the context by name. So
 * the fixtures are mounted the way a real context mounts them, and the framework subscribers are
 * registered as facade init() registers them. What is asserted is unchanged - the same reads
 * before and after the same mutations - which is the point: the mechanism moved, the contract
 * did not.
 */
final class CollectionPointMutationCacheTest extends TestCase
{
    private const string AGENT_ID = 'unit-point-mutation-host';

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRuntime = null;

    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRuntime = Hilos::$rt;
        $this->previousDb = Hilos::$db;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new OutboundRtSyncSubscriber());
        RtTruthSourceRegistry::register(PointMutationRtContext::COLLECTION, true, self::AGENT_ID);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(PointMutationRtContext::COLLECTION, self::AGENT_ID);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRuntime;
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    public function testARemovedKeyStopsAnsweringReads(): void
    {
        $collection = $this->mounted();
        $collection->actions->put('a', 'first');

        // The read is the point: it puts a wrapper for 'a' into the cache the removal must clear.
        $this->assertNotNull($collection['a']);

        $collection->actions->drop('a');

        $this->assertNull($collection['a']);
    }

    public function testAReusedKeyAnswersWithTheStateItNowHolds(): void
    {
        $collection = $this->mounted();
        $collection->actions->put('a', 'first');
        $this->assertSame('first', $collection['a']?->mark());

        // The store takes the second row under the same key, so the wrapper cached above is
        // now wrapped around a state nobody holds any more.
        $collection->actions->put('a', 'second');

        $this->assertSame('second', $collection['a']?->mark());
    }

    public function testAWalkOutlivesAPointMutationMadeInsideIt(): void
    {
        $collection = $this->mounted();
        $collection->actions->put('a', 'first');
        $collection->actions->put('b', 'second');
        $collection->actions->put('c', 'third');

        $walked = [];
        foreach ($collection as $key => $item) {
            $walked[] = $key;
            if ($key === 'a') {
                $collection->actions->drop('c');
            }
        }

        // A full cache reset would have emptied the walk after its very first step.
        $this->assertSame(['a', 'b'], $walked);
    }

    public function testDirectUnsetIsRefusedOnARuntimeCollection(): void
    {
        $collection = $this->mounted();
        $collection->actions->put('a', 'first');

        $this->expectException(RtCollectionDirectUnsetException::class);
        unset($collection['a']);
    }

    public function testDirectUnsetIsRefusedOnADbCollection(): void
    {
        $collection = PointMutationDbCollection::init();

        $this->expectException(DirectUnsetException::class);
        unset($collection[1]);
    }

    /**
     * The database half of the same contract. Its point mutations used to drop the wrapper from
     * inside the collection actions, which left the road through the sync applicator uncovered;
     * now the mirror announces and the same subscriber repairs both roads.
     *
     * @throws HilosException When the fixture object cannot be built or stored
     */
    public function testAReusedDbKeyAnswersWithTheObjectItNowHolds(): void
    {
        $objects = $this->mountedDb();
        $objects['1'] = PointMutationObject::fromEntity(PointMutationEntity::withId(1, 'first'));
        $view = $this->dbView();
        $this->assertSame('first', $view['1']?->mark());

        $objects['1'] = PointMutationObject::fromEntity(PointMutationEntity::withId(1, 'second'));

        $this->assertSame('second', $view['1']?->mark());
    }

    /**
     * @throws HilosException When the fixture object cannot be built or stored
     */
    public function testARemovedDbKeyStopsAnsweringReads(): void
    {
        $objects = $this->mountedDb();
        $objects['1'] = PointMutationObject::fromEntity(PointMutationEntity::withId(1, 'first'));
        $view = $this->dbView();
        // The read is the point: it puts a wrapper for '1' into the cache the removal must clear.
        $this->assertNotNull($view['1']);

        unset($objects['1']);

        $this->assertNull($view['1']);
    }

    /**
     * A full read caches a wrapper per row, and the wrapper holds its object by reference. The
     * loop that builds them reuses one variable, so unless the binding is dropped per row every
     * wrapper but the last follows the walk to the last object - and the corruption is invisible
     * in the answer the read itself gives, because each row is serialized before the next one
     * rebinds it.
     *
     * @throws HilosException When the fixture objects cannot be built or stored
     */
    public function testAFullReadLeavesEveryCachedWrapperOnItsOwnRow(): void
    {
        $objects = $this->mountedDb();
        $objects['1'] = PointMutationObject::fromEntity(PointMutationEntity::withId(1, 'first'));
        $objects['2'] = PointMutationObject::fromEntity(PointMutationEntity::withId(2, 'second'));
        $view = $this->dbView();

        $this->assertSame(
            ['first', 'second'],
            array_column($view->toArray(), 'mark'),
        );

        $this->assertSame('first', $view['1']?->mark());
        $this->assertSame('second', $view['2']?->mark());
    }

    public function testANullOffsetUnsetStaysSilentOnBothCollections(): void
    {
        $collection = $this->mounted();
        $dbCollection = PointMutationDbCollection::init();

        unset($collection[null]);
        unset($dbCollection[null]);

        $this->expectNotToPerformAssertions();
    }

    /**
     * Mounts the object collection through a DB context, the way a project context mounts it.
     *
     * @return PointMutationObjects Mounted object collection, the mirror these cases mutate
     */
    private function mountedDb(): PointMutationObjects
    {
        $objects = PointMutationObjects::initEmpty();
        $view = PointMutationDbCollection::init();
        $view->setObjectCollection($objects);
        Hilos::$db = PointMutationDbContext::create($objects, $view);

        return $objects;
    }

    /**
     * Hands back the view mounted by the last {@see self::mountedDb()} call.
     *
     * @return PointMutationDbCollection Mounted DB view collection
     * @throws HilosException When no DB context is mounted
     */
    private function dbView(): PointMutationDbCollection
    {
        $view = Hilos::$db?->getDbItemCollection(PointMutationDbContext::COLLECTION);

        return $view instanceof PointMutationDbCollection
            ? $view
            : throw new CollectionNotFoundException('Point-mutation fixture DB collection is not mounted');
    }

    /**
     * Mounts the collection through a runtime context and hands back its view.
     *
     * Through a context rather than by hand, because the subscriber that repairs the cache finds
     * the view by asking the context for the name the fact carries. A collection assembled
     * outside one is reachable by nobody and would report a cache that is never repaired.
     *
     * @return PointMutationRtCollection Mounted view of a fresh state collection
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    private function mounted(): PointMutationRtCollection
    {
        $context = new PointMutationRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$rt = $context;

        return $context->collection();
    }
}

/**
 * Runtime context mounting the one collection these cases mutate.
 */
final class PointMutationRtContext extends RtContext
{
    public const string COLLECTION = 'unit-point-mutation-cache';

    /**
     * Registers the state collection and its view, as a project context does.
     *
     * @throws StateCollectionNotFoundException When the state collection was not registered first
     */
    public function configure(): void
    {
        $this->_stateCollections[self::COLLECTION] = PointMutationRtStates::init();
        $this->setRepresent(
            self::COLLECTION,
            PointMutationRtCollection::class,
            PointMutationRtActions::class,
        );
    }

    /**
     * Hands back the mounted view without going through the magic getter.
     *
     * @return PointMutationRtCollection Mounted view collection
     * @throws RtCollectionNotFoundException When configure() has not run
     */
    public function collection(): PointMutationRtCollection
    {
        $collection = $this->getRtCollection(self::COLLECTION);

        return $collection instanceof PointMutationRtCollection
            ? $collection
            : throw new RtCollectionNotFoundException('Point-mutation fixture collection is not mounted');
    }
}

/**
 * Minimal runtime state item carrying a mark that tells two rows of one key apart.
 */
final class PointMutationRtState extends RtState
{
    private string $id = '';

    private string $mark = '';

    public static function create(string $id, string $mark): self
    {
        $state = new self();
        $state->id = $id;
        $state->mark = $mark;

        return $state;
    }

    public static function fromRow(array $row): static
    {
        return self::create((string)$row['id'], (string)$row['mark']);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMark(): string
    {
        return $this->mark;
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'mark' => $this->mark];
    }
}

/**
 * Minimal runtime state collection for the point-mutation fixtures.
 *
 * @extends RtStates<PointMutationRtState>
 */
final class PointMutationRtStates extends RtStates
{
    public const string STATE_CLASS = PointMutationRtState::class;
}

/**
 * Minimal runtime view item reporting the mark of the state it wraps.
 *
 * @extends RtItem<PointMutationRtState>
 */
final class PointMutationRtItem extends RtItem
{
    public function mark(): string
    {
        return $this->_state->getMark();
    }

    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

/**
 * Minimal runtime view collection for the point-mutation fixtures.
 *
 * @extends RtCollection<PointMutationRtItem, PointMutationRtActions>
 */
final class PointMutationRtCollection extends RtCollection
{
    protected function createRtItem(RtState &$state): RtItem
    {
        return new PointMutationRtItem($state);
    }
}

/**
 * Exposes the protected point mutations of the base collection actions.
 *
 * @extends RtActions<PointMutationRtItem, PointMutationRtCollection, PointMutationRtStates>
 */
final class PointMutationRtActions extends RtActions
{
    /**
     * Adds one row, or replaces the row already standing under that key.
     *
     * @param string $id State ID to write
     * @param string $mark Value telling this row apart from an earlier one of the same key
     * @throws HilosException On a missing callback, truth-source refusal, or sync failure
     */
    public function put(string $id, string $mark): void
    {
        $this->addStateToCollection(PointMutationRtState::create($id, $mark));
    }

    /**
     * Removes one row by key.
     *
     * @param string $id State ID to remove
     * @throws HilosException On a missing callback, truth-source refusal, or sync failure
     */
    public function drop(string $id): void
    {
        $this->removeStateFromCollection($id);
    }
}

/**
 * Minimal DB collection used for the direct-unset contract and the mirror cache cases.
 */
final class PointMutationDbCollection extends DbCollection
{
    protected function createDbItem(Object_ &$object): DbItem
    {
        return new PointMutationDbItem($object);
    }
}

/**
 * DB context mounting the one mirror these cases mutate.
 */
final class PointMutationDbContext extends DbContext
{
    public const string COLLECTION = 'unit-point-mutation-db';

    /**
     * @param PointMutationObjects $objects Mirror to mount
     * @param PointMutationDbCollection $view View of the mirror to mount
     * @return self Mounted context
     */
    public static function create(PointMutationObjects $objects, PointMutationDbCollection $view): self
    {
        $context = new self();
        $context->_objectCollections[self::COLLECTION] = $objects;
        $context->_dbItemCollections[self::COLLECTION] = $view;

        return $context;
    }

    public function configure(): void
    {
    }
}

/**
 * Minimal single-column entity carrying a mark that tells two rows of one key apart.
 */
final class PointMutationEntity extends Entity
{
    public const string _table = 'point_mutation_test';
    public const string _primary = 'id';
    public const array _columns = ['id', 'mark'];
    public const array _types = ['id' => 'integer', 'mark' => 'string'];

    public ?int $id = null;

    public string $mark = '';

    /**
     * @param int $id Primary key
     * @param string $mark Value telling this row apart from another one of the same key
     * @return self Built entity
     */
    public static function withId(int $id, string $mark): self
    {
        $entity = new self();
        $entity->id = $id;
        $entity->mark = $mark;

        return $entity;
    }
}

/**
 * Minimal object fixture wrapping the point-mutation entity.
 */
final class PointMutationObject extends Object_
{
    public const string ENTITY_CLASS = PointMutationEntity::class;

    /**
     * @return string Mark of the wrapped entity
     */
    public function getMark(): string
    {
        return $this->entity->mark;
    }
}

/**
 * Minimal object collection fixture, named so its mutations can be announced.
 */
final class PointMutationObjects extends Objects
{
    public const string OBJECT_CLASS = PointMutationObject::class;
    public const string COLLECTION_KEY = PointMutationDbContext::COLLECTION;
}

/**
 * Minimal DB view item reporting the mark of the object it wraps.
 */
final class PointMutationDbItem extends DbItem
{
    /**
     * @return string Mark of the wrapped object
     */
    public function mark(): string
    {
        return $this->_object->getMark();
    }
}

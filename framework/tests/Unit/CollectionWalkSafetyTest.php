<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\CollectionNotFoundException;
use Hilos\Database\Object\Collection\ObjectCollection;
use Hilos\Database\Object\FilteredCollection;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use Hilos\HilosException;
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
 * Unit tests for what a walk over a framework collection observes while it is being mutated.
 *
 * Every collection here used to keep the walk in a numeric cursor into a live key list, so
 * dropping a row at or before the cursor shifted every later key down by one and the walk
 * silently skipped a row. A whole discipline was built on top of that - collect the keys
 * first, mutate afterwards - and it was walked around often enough to cost a wrong session
 * (HIL-673).
 *
 * The contract pinned here is the one that replaced it: a walk takes its own snapshot of the
 * keys when it starts and resolves each key when it reaches it. So a row that left is skipped,
 * a row that arrived is not seen, and two walks over one collection do not share a cursor
 * because there is no cursor to share.
 *
 * The same five cases run against all seven collections, because the contract is one contract:
 * the two view collections walk by the keys of their truth source, and the five storage
 * collections walk by their own.
 */
final class CollectionWalkSafetyTest extends TestCase
{
    private const string AGENT_ID = 'unit-collection-walk-safety-host';

    private ?RtContext $previousRuntime = null;

    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        $this->previousRuntime = Hilos::$rt;
        $this->previousDb = Hilos::$db;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::register(WalkRtContext::COLLECTION, true, self::AGENT_ID);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(WalkRtContext::COLLECTION, self::AGENT_ID);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRuntime;
        Hilos::$db = $this->previousDb;

        parent::tearDown();
    }

    /**
     * @throws HilosException When a fixture collection cannot be mounted or mutated
     */
    public function testDroppingTheRowUnderTheWalkLetsItReachTheEnd(): void
    {
        foreach ($this->subjects() as $subject) {
            $subject->put('a');
            $subject->put('b');
            $subject->put('c');

            $walked = [];
            foreach ($subject->collection() as $key => $row) {
                $walked[] = $key;
                $subject->drop((string)$key);
            }

            $this->assertSame(['a', 'b', 'c'], $walked, $subject->name());
        }
    }

    /**
     * @throws HilosException When a fixture collection cannot be mounted or mutated
     */
    public function testDroppingARowAheadOfTheWalkSkipsThatOneAndNoOther(): void
    {
        foreach ($this->subjects() as $subject) {
            $subject->put('a');
            $subject->put('b');
            $subject->put('c');

            $walked = [];
            foreach ($subject->collection() as $key => $row) {
                $walked[] = $key;
                if ($key === 'a') {
                    $subject->drop('b');
                }
            }

            $this->assertSame(['a', 'c'], $walked, $subject->name());
        }
    }

    /**
     * @throws HilosException When a fixture collection cannot be mounted or mutated
     */
    public function testARowAddedDuringAWalkIsNotSeenByIt(): void
    {
        foreach ($this->subjects() as $subject) {
            if (!$subject->canGrowDuringAWalk()) {
                continue;
            }
            $subject->put('a');
            $subject->put('b');

            $walked = [];
            foreach ($subject->collection() as $key => $row) {
                $walked[] = $key;
                if ($key === 'a') {
                    $subject->put('c');
                }
            }

            $this->assertSame(['a', 'b'], $walked, $subject->name());
        }
    }

    /**
     * @throws HilosException When a fixture collection cannot be mounted or mutated
     */
    public function testANestedWalkLeavesTheOuterOneWhereItStood(): void
    {
        foreach ($this->subjects() as $subject) {
            $subject->put('a');
            $subject->put('b');
            $subject->put('c');

            $outer = [];
            $inner = [];
            foreach ($subject->collection() as $outerKey => $outerRow) {
                $outer[] = $outerKey;
                if ($outerKey !== 'a') {
                    continue;
                }
                foreach ($subject->collection() as $innerKey => $innerRow) {
                    $inner[] = $innerKey;
                }
            }

            $this->assertSame(['a', 'b', 'c'], $outer, $subject->name());
            $this->assertSame(['a', 'b', 'c'], $inner, $subject->name());
        }
    }

    /**
     * @throws HilosException When a fixture collection cannot be mounted
     */
    public function testAnEmptyCollectionWalksZeroTimes(): void
    {
        foreach ($this->subjects() as $subject) {
            $walked = [];
            foreach ($subject->collection() as $key => $row) {
                $walked[] = $key;
            }

            $this->assertSame([], $walked, $subject->name());
        }
    }

    /**
     * A manual DB collection owns its rows instead of wrapping a mirror, so it walks its own
     * keys. It has no public way to drop one, which is why it is not one of the subjects above.
     *
     * @throws HilosException When the fixture item cannot be built or added
     */
    public function testAManualDbCollectionWalksItsOwnKeys(): void
    {
        $collection = WalkDbCollection::initEmpty();
        $this->assertSame([], array_keys(iterator_to_array($collection)));

        $collection->add(new WalkDbItem(WalkObject::fromEntity(WalkEntity::withId(1))));
        $collection->add(new WalkDbItem(WalkObject::fromEntity(WalkEntity::withId(2))));

        // The keys are integers, not the strings add() derives them from: a numeric string is
        // an integer once it is an array key, and the walk hands back what the store holds.
        $walked = [];
        foreach ($collection as $key => $item) {
            $walked[] = $key;
            if ($key === 1) {
                $collection->add(new WalkDbItem(WalkObject::fromEntity(WalkEntity::withId(3))));
            }
        }

        $this->assertSame([1, 2], $walked);
    }

    /**
     * Builds one fresh set of subjects, so no case inherits rows from the one before it.
     *
     * @return list<WalkSubject> Every collection the contract covers
     * @throws HilosException When a fixture context cannot mount its own collection
     */
    private function subjects(): array
    {
        return [
            new RtStatesWalkSubject(),
            new ObjectsWalkSubject(),
            new FilteredCollectionWalkSubject(),
            new ObjectCollectionWalkSubject(),
            new EntityCollectionWalkSubject(),
            new RtCollectionWalkSubject(),
            new DbCollectionWalkSubject(),
        ];
    }
}

/**
 * One collection under test, reduced to what a walk case asks of it.
 */
abstract class WalkSubject
{
    /**
     * @return string Collection name, reported when a case fails
     */
    abstract public function name(): string;

    /**
     * @return iterable<string, mixed> The collection a case walks
     */
    abstract public function collection(): iterable;

    /**
     * Puts one row under the given key.
     *
     * @param string $key Row key
     * @throws HilosException Whatever a subscriber to the announcement raises
     */
    abstract public function put(string $key): void;

    /**
     * Drops the row under the given key.
     *
     * @param string $key Row key
     * @throws HilosException Whatever a subscriber to the announcement raises
     */
    abstract public function drop(string $key): void;

    /**
     * @return bool True when a row can be added through the public API after construction
     */
    public function canGrowDuringAWalk(): bool
    {
        return true;
    }
}

/**
 * The runtime store, walking its own state ids.
 */
final class RtStatesWalkSubject extends WalkSubject
{
    private WalkRtStates $store;

    public function __construct()
    {
        $this->store = WalkRtStates::init();
    }

    public function name(): string
    {
        return RtStates::class;
    }

    public function collection(): iterable
    {
        return $this->store;
    }

    public function put(string $key): void
    {
        $this->store->add(WalkRtState::create($key));
    }

    public function drop(string $key): void
    {
        $this->store->remove($key);
    }
}

/**
 * The database mirror, walking its own object keys.
 */
final class ObjectsWalkSubject extends WalkSubject
{
    private WalkObjects $store;

    public function __construct()
    {
        $this->store = WalkObjects::initEmpty();
    }

    public function name(): string
    {
        return Objects::class;
    }

    public function collection(): iterable
    {
        return $this->store;
    }

    public function put(string $key): void
    {
        $this->store[$key] = WalkObject::fromEntity(WalkEntity::withId(1));
    }

    public function drop(string $key): void
    {
        unset($this->store[$key]);
    }
}

/**
 * A filtered view of a mirror, walking the keys the filter produced.
 *
 * Its rows arrive with the constructor and there is no public way to add one afterwards, so
 * the case about rows added mid-walk does not apply here.
 */
final class FilteredCollectionWalkSubject extends WalkSubject
{
    private WalkObjects $source;

    /** @var array<int|string, Object_> Rows handed to the filtered collection when it is built */
    private array $rows = [];

    private ?FilteredCollection $filtered = null;

    public function __construct()
    {
        $this->source = WalkObjects::initEmpty();
    }

    public function name(): string
    {
        return FilteredCollection::class;
    }

    public function collection(): iterable
    {
        return $this->filtered();
    }

    public function put(string $key): void
    {
        $this->rows[$key] = WalkObject::fromEntity(WalkEntity::withId(1));
    }

    public function drop(string $key): void
    {
        $filtered = $this->filtered();
        unset($filtered[$key]);
    }

    public function canGrowDuringAWalk(): bool
    {
        return false;
    }

    /**
     * Builds the filtered collection on first use, so the rows a case put in are all in it.
     *
     * @return FilteredCollection Filtered view of the source mirror
     */
    private function filtered(): FilteredCollection
    {
        return $this->filtered ??= new FilteredCollection($this->source, $this->rows);
    }
}

/**
 * The light object container, walking its own keys.
 */
final class ObjectCollectionWalkSubject extends WalkSubject
{
    private ObjectCollection $store;

    public function __construct()
    {
        $this->store = ObjectCollection::empty();
    }

    public function name(): string
    {
        return ObjectCollection::class;
    }

    public function collection(): iterable
    {
        return $this->store;
    }

    public function put(string $key): void
    {
        $this->store->add(WalkObject::fromEntity(WalkEntity::withId(1)), $key);
    }

    public function drop(string $key): void
    {
        $this->store->remove($key);
    }
}

/**
 * The light entity container, walking its own keys.
 */
final class EntityCollectionWalkSubject extends WalkSubject
{
    private EntityCollection $entities;

    public function __construct()
    {
        $this->entities = EntityCollection::empty();
    }

    public function name(): string
    {
        return EntityCollection::class;
    }

    public function collection(): iterable
    {
        return $this->entities;
    }

    public function put(string $key): void
    {
        $this->entities->add(WalkEntity::withId(1), $key);
    }

    public function drop(string $key): void
    {
        $this->entities->remove($key);
    }
}

/**
 * The runtime view, walking the keys of the state collection behind it.
 *
 * Mounted through a context, because a row leaving the store repairs the view's wrapper cache
 * by announcing it, and the subscriber finds the view by asking the context for the name.
 */
final class RtCollectionWalkSubject extends WalkSubject
{
    private WalkRtContext $context;

    /**
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function __construct()
    {
        $this->context = new WalkRtContext();
        $this->context->configure();
        $this->context->bindStateCollectionNames();
        Hilos::$rt = $this->context;
    }

    public function name(): string
    {
        return RtCollection::class;
    }

    public function collection(): iterable
    {
        return $this->context->collection();
    }

    public function put(string $key): void
    {
        $this->context->collection()->actions->put($key);
    }

    public function drop(string $key): void
    {
        $this->context->collection()->actions->drop($key);
    }
}

/**
 * The database view in automatic mode, walking the keys of the mirror behind it.
 */
final class DbCollectionWalkSubject extends WalkSubject
{
    private WalkObjects $store;

    private WalkDbCollection $view;

    public function __construct()
    {
        $this->store = WalkObjects::initEmpty();
        $this->view = WalkDbCollection::init();
        $this->view->setObjectCollection($this->store);
        Hilos::$db = WalkDbContext::create($this->store, $this->view);
    }

    public function name(): string
    {
        return DbCollection::class;
    }

    public function collection(): iterable
    {
        return $this->view;
    }

    public function put(string $key): void
    {
        $this->store[$key] = WalkObject::fromEntity(WalkEntity::withId(1));
    }

    public function drop(string $key): void
    {
        unset($this->store[$key]);
    }
}

/**
 * Runtime context mounting the one collection the runtime view cases walk.
 */
final class WalkRtContext extends RtContext
{
    public const string COLLECTION = 'unit-collection-walk-safety';

    /**
     * Registers the state collection and its view, as a project context does.
     *
     * @throws StateCollectionNotFoundException When the state collection was not registered first
     */
    public function configure(): void
    {
        $this->_stateCollections[self::COLLECTION] = WalkRtStates::init();
        $this->setRepresent(self::COLLECTION, WalkRtCollection::class, WalkRtActions::class);
    }

    /**
     * @return WalkRtCollection Mounted view collection
     * @throws RtCollectionNotFoundException When configure() has not run
     */
    public function collection(): WalkRtCollection
    {
        $collection = $this->getRtCollection(self::COLLECTION);

        return $collection instanceof WalkRtCollection
            ? $collection
            : throw new RtCollectionNotFoundException('Walk-safety fixture collection is not mounted');
    }
}

/**
 * Minimal runtime state item for the walk-safety fixtures.
 */
final class WalkRtState extends RtState
{
    private string $id = '';

    /**
     * @param string $id State ID
     * @return self Built state
     */
    public static function create(string $id): self
    {
        $state = new self();
        $state->id = $id;

        return $state;
    }

    public static function fromRow(array $row): static
    {
        return self::create((string)$row['id']);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function toArray(): array
    {
        return ['id' => $this->id];
    }
}

/**
 * Minimal runtime state collection for the walk-safety fixtures.
 *
 * @extends RtStates<WalkRtState>
 */
final class WalkRtStates extends RtStates
{
    public const string STATE_CLASS = WalkRtState::class;
}

/**
 * Minimal runtime view item for the walk-safety fixtures.
 *
 * @extends RtItem<WalkRtState>
 */
final class WalkRtItem extends RtItem
{
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

/**
 * Minimal runtime view collection for the walk-safety fixtures.
 *
 * @extends RtCollection<WalkRtItem, WalkRtActions>
 */
final class WalkRtCollection extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new WalkRtItem($state);
    }
}

/**
 * Exposes the point mutations of the base collection actions, the road a project mutates by.
 *
 * @extends RtActions<WalkRtItem, WalkRtCollection, WalkRtStates>
 */
final class WalkRtActions extends RtActions
{
    /**
     * Adds one row under the given key.
     *
     * @param string $id State ID to write
     * @throws HilosException On a missing callback, truth-source refusal, or sync failure
     */
    public function put(string $id): void
    {
        $this->addStateToCollection(WalkRtState::create($id));
    }

    /**
     * Removes the row under the given key.
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
 * DB context mounting the one mirror the database view cases walk.
 */
final class WalkDbContext extends DbContext
{
    public const string COLLECTION = 'unit-collection-walk-safety-db';

    /**
     * @param WalkObjects $objects Mirror to mount
     * @param WalkDbCollection $view View of the mirror to mount
     * @return self Mounted context
     */
    public static function create(WalkObjects $objects, WalkDbCollection $view): self
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
 * Minimal single-column entity for the walk-safety fixtures.
 */
final class WalkEntity extends Entity
{
    public const string _table = 'collection_walk_safety_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;

    /**
     * @param int $id Primary key
     * @return self Built entity
     */
    public static function withId(int $id): self
    {
        $entity = new self();
        $entity->id = $id;

        return $entity;
    }
}

/**
 * Minimal object fixture wrapping the walk-safety entity.
 */
final class WalkObject extends Object_
{
    public const string ENTITY_CLASS = WalkEntity::class;
}

/**
 * Minimal object collection fixture, named so its mutations can be announced.
 */
final class WalkObjects extends Objects
{
    public const string OBJECT_CLASS = WalkObject::class;
    public const string COLLECTION_KEY = WalkDbContext::COLLECTION;
}

/**
 * Minimal DB view item for the walk-safety fixtures.
 */
final class WalkDbItem extends DbItem
{
}

/**
 * Minimal DB view collection for the walk-safety fixtures.
 */
final class WalkDbCollection extends DbCollection
{
    protected function createDbItem(Object_ $object): DbItem
    {
        return new WalkDbItem($object);
    }
}

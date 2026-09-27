<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\UnknownLazyStrategyException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Hilos;
use Hilos\HilosException;
use PHPUnit\Framework\TestCase;

/**
 * A collection mounted under LAZY_STRATEGY_NONE keeps its own promise (HIL-1144): the table
 * comes in on the collection's first read, whichever entrance handed it out.
 *
 * The entrance here is DbContext::getObjectCollection(), the one that used to hand the collection
 * out as it was. DbContext::__get() was the only door that loaded, so a NONE collection reached
 * past it answered null to a row, 0 to a count and nothing to a walk, with no error. Every case
 * reads a collection nobody read through the View layer first.
 */
final class ObjectsWholeOnFirstReadTest extends TestCase
{
    private const string COLLECTION_KEY = 'whole_on_first_read';

    /** A strategy none of the four constants name. */
    private const int UNKNOWN_STRATEGY = 9;

    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
        WholeOnFirstReadEntities::$tableReads = 0;
        Hilos::$db = WholeOnFirstReadDbContext::create(
            self::COLLECTION_KEY,
            WholeOnFirstReadObjects::initDB(Objects::LAZY_STRATEGY_NONE),
            WholeOnFirstReadDbCollection::init(),
        );
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
    }

    /**
     * @throws HilosException When the collection refuses the read
     */
    public function testARowByKeyArrivesFromAFreshCollection(): void
    {
        $collection = $this->freshCollection();

        $row = $collection[2];

        $this->assertInstanceOf(WholeOnFirstReadObject::class, $row);
        $this->assertSame(2, $row->id);
        $this->assertSame(1, WholeOnFirstReadEntities::$tableReads);
    }

    /**
     * @throws HilosException When the collection refuses the read
     */
    public function testACountArrivesFromAFreshCollection(): void
    {
        $this->assertSame(2, $this->freshCollection()->count());
    }

    /**
     * @throws HilosException When the collection refuses the walk
     */
    public function testAWalkAnswersWithBothRows(): void
    {
        $collection = $this->freshCollection();

        $this->assertSame([1, 2], $collection->keys());

        $walked = [];
        foreach ($collection as $key => $object) {
            $walked[$key] = $object->id;
        }
        $this->assertSame([1 => 1, 2 => 2], $walked);
        // Two reads of the set, one read of the table.
        $this->assertSame(1, WholeOnFirstReadEntities::$tableReads);
    }

    /**
     * @throws HilosException When the collection refuses the read
     */
    public function testFirstAndLastAreTheTablesOwn(): void
    {
        $collection = $this->freshCollection();

        $this->assertSame(1, $collection->first()?->id);
        $this->assertSame(2, $collection->last()?->id);
        $this->assertSame(1, WholeOnFirstReadEntities::$tableReads);
    }

    /**
     * The object layer's membership test is pure memory: it is what the sync applicator looks at
     * mirrors through, and a mirror that read its table on every incoming change would lift whole
     * tables in processes that never read them.
     *
     * @throws HilosException When the collection refuses the read
     */
    public function testMembershipOfTheObjectLayerIsMemoryUntilSomethingReads(): void
    {
        $collection = $this->freshCollection();

        $this->assertFalse(isset($collection[1]));
        $this->assertSame(0, WholeOnFirstReadEntities::$tableReads);

        $this->assertNotNull($collection[1]);

        $this->assertTrue(isset($collection[1]));
        $this->assertSame(1, WholeOnFirstReadEntities::$tableReads);
    }

    /**
     * The View layer's membership test is a reader's question, and a miss in memory is answered by
     * the read: isset() on a fresh collection is true for a row the table holds.
     *
     * @throws HilosException When the collection refuses the read
     */
    public function testMembershipOfTheViewLayerReadsTheTable(): void
    {
        $collection = $this->freshCollection();
        $view = WholeOnFirstReadDbCollection::init();
        $view->setObjectCollection($collection);

        $this->assertTrue(isset($view[2]));
        $this->assertFalse(isset($view[3]));
        $this->assertSame(1, WholeOnFirstReadEntities::$tableReads);
    }

    /**
     * The read fills in what memory does not hold rather than replacing it, so a row put there
     * before the first read - by a lookup by key, by a write on this node - keeps its instance under
     * whatever wrapper was already handed out for it.
     *
     * @throws HilosException When the collection refuses the read
     */
    public function testARowHeldBeforeTheFirstReadKeepsItsInstance(): void
    {
        $collection = $this->freshCollection();
        $held = $collection->holdRow(1);

        $this->assertSame(2, $collection->count());
        $this->assertSame($held, $collection[1]);
    }

    /**
     * LAZY_STRATEGY_NONE is the strategy property's default, so an empty collection is under it
     * too - and reads no table, because it made no promise.
     *
     * @throws HilosException When the collection refuses the read
     */
    public function testAnEmptyCollectionMadeNoPromise(): void
    {
        $collection = WholeOnFirstReadObjects::initEmpty();

        $this->assertSame(0, $collection->count());
        $this->assertSame([], $collection->keys());
        $this->assertNull($collection[1]);
        $this->assertSame(0, WholeOnFirstReadEntities::$tableReads);
    }

    /**
     * @throws HilosException When the mount refuses the strategy
     */
    public function testAnUnknownStrategyIsRefusedAtTheMount(): void
    {
        $this->expectException(UnknownLazyStrategyException::class);
        $this->expectExceptionMessage(
            'Unknown lazy loading strategy ' . self::UNKNOWN_STRATEGY . " for collection '" . self::COLLECTION_KEY . "'"
        );

        WholeOnFirstReadObjects::initDB(self::UNKNOWN_STRATEGY);
    }

    /**
     * Takes the collection through the entrance that never loaded it.
     *
     * @return WholeOnFirstReadObjects Collection as the object layer hands it out, unread
     * @throws HilosException When the process is refused the read
     */
    private function freshCollection(): WholeOnFirstReadObjects
    {
        $collection = Hilos::$db?->getObjectCollection(self::COLLECTION_KEY);
        $this->assertInstanceOf(WholeOnFirstReadObjects::class, $collection);
        $this->assertSame(0, WholeOnFirstReadEntities::$tableReads);

        return $collection;
    }
}

/**
 * Entity fixture whose table is two rows held in the test.
 */
final class WholeOnFirstReadEntity extends Entity
{
    public const string _table = 'whole_on_first_read_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;

    /**
     * @param int $id Row id
     * @return self A row of the fake table, marked as read from it
     */
    public static function withId(int $id): self
    {
        $entity = new self();
        $entity->id = $id;
        $entity->flushRelated();

        return $entity;
    }
}

/**
 * Entity collection fixture answering with the fake table and counting how often it was asked.
 */
final class WholeOnFirstReadEntities extends EntityCollection
{
    public const string ENTITY_CLASS = WholeOnFirstReadEntity::class;

    /** @var int How many times the fake table was read whole */
    public static int $tableReads = 0;

    /**
     * @return static The two rows the fake table holds
     */
    public static function initFullDB(): static
    {
        self::$tableReads++;
        $collection = new static();
        foreach ([1, 2] as $id) {
            $collection->add(WholeOnFirstReadEntity::withId($id), (string) $id);
        }

        return $collection;
    }
}

/**
 * Object fixture wrapping the entity.
 */
final class WholeOnFirstReadObject extends Object_
{
    public const string ENTITY_CLASS = WholeOnFirstReadEntity::class;
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
 * Object collection fixture, keyed so the context can mount it.
 */
final class WholeOnFirstReadObjects extends Objects
{
    public const string OBJECT_CLASS = WholeOnFirstReadObject::class;
    public const string ENTITY_COLLECTION_CLASS = WholeOnFirstReadEntities::class;
    public const string COLLECTION_KEY = 'whole_on_first_read';

    /**
     * Puts one row into memory the way a lookup by key does, announcing nothing.
     *
     * @param int $id Row to hold
     * @return WholeOnFirstReadObject The instance now held
     */
    public function holdRow(int $id): WholeOnFirstReadObject
    {
        $object = WholeOnFirstReadObject::fromEntity(WholeOnFirstReadEntity::withId($id));
        $this->hydrate($id, $object);

        return $object;
    }
}

/**
 * Minimal DB collection fixture wrapping the object collection.
 */
final class WholeOnFirstReadDbCollection extends DbCollection
{
}

/**
 * Test context mounting the one collection without a real DB.
 */
final class WholeOnFirstReadDbContext extends HilosDbContext
{
    /**
     * @param string $name Name the collection is mounted under
     * @param Objects $objectCollection Store to mount
     * @param DbCollection $dbCollection View to mount over it
     * @return self Context holding the one collection
     */
    public static function create(string $name, Objects $objectCollection, DbCollection $dbCollection): self
    {
        $context = new self();
        $context->_objectCollections[$name] = $objectCollection;
        $context->_dbItemCollections[$name] = $dbCollection;

        return $context;
    }

    public function configure(): void
    {
    }
}

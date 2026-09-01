<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;
use PHPUnit\Framework\TestCase;

/**
 * Reading a set owned by somebody else's key (HIL-781).
 *
 * A browser list joins its rows in by a foreign key, and until this read existed the join was a
 * walk over the joined collection - which, for a key-lazy collection, answers with whatever the
 * process happened to have fetched by key already. Six identity rows in the table, an empty list
 * on the screen, and nothing anywhere saying why.
 *
 * So the cases below all start from an EMPTY collection: that is the state the defect lived in,
 * and a read that only works once the rows are already in memory would pass a test written any
 * other way while changing nothing on the screen.
 */
final class DbCollectionColumnReadTest extends TestCase
{
    protected function setUp(): void
    {
        ColumnReadEntity::setTableRows([
            ['id' => 1, 'user_id' => 6],
            ['id' => 2, 'user_id' => 6],
            ['id' => 3, 'user_id' => 7],
        ]);
    }

    /**
     * @throws HilosException When the collection refuses the read
     */
    public function testTheRowsOfOneOwnerArriveWhenNothingIsInMemoryYet(): void
    {
        $collection = ColumnReadCollection::overEmptyKeyLazyStorage();

        $matched = $collection->whereColumnIs(ColumnReadObject::userId, 6);

        $this->assertSame(['1', '2'], $this->idsOf($matched));
    }

    /**
     * The same read spelled with the table's own column name, since a caller at the ORM boundary
     * has that name and not the object layer's.
     *
     * @throws HilosException When the collection refuses the read
     */
    public function testTheColumnNameOfTheTableNamesTheSameField(): void
    {
        $collection = ColumnReadCollection::overEmptyKeyLazyStorage();

        $matched = $collection->whereColumnIs(ColumnReadEntity::user_id, 7);

        $this->assertSame(['3'], $this->idsOf($matched));
    }

    /**
     * @throws HilosException When the collection refuses the read
     */
    public function testSeveralOwnersAreAnsweredByOneQuery(): void
    {
        $collection = ColumnReadCollection::overEmptyKeyLazyStorage();

        $matched = $collection->whereColumnIn(ColumnReadObject::userId, 6, 7);

        $this->assertSame(['1', '2', '3'], $this->idsOf($matched));
        $this->assertSame(1, ColumnReadEntity::queryCount());
    }

    /**
     * @throws HilosException When the collection refuses the read
     */
    public function testAColumnThisEntityDoesNotHaveIsRefusedByName(): void
    {
        $collection = ColumnReadCollection::overEmptyKeyLazyStorage();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("database collection 'column_read' has no column 'ownerId'");

        $collection->whereColumnIs('ownerId', 6);
    }

    /**
     * A collection with no entity behind it has no column either, so it refuses by the same name
     * rather than dying on the empty class it would otherwise dereference.
     *
     * @throws HilosException When the collection refuses the read
     */
    public function testACollectionWithNoEntityBehindItRefusesRatherThanFatals(): void
    {
        $collection = ColumnReadUnconfiguredCollection::overUnconfiguredStorage();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("database collection '' has no column 'userId'");

        $collection->whereColumnIs(ColumnReadObject::userId, 6);
    }

    /**
     * A missing optional key and an empty set of keys are both an empty answer, and neither is
     * worth a query: a join whose anchor carries no owner has nothing to join to.
     *
     * @throws HilosException When the collection refuses the read
     */
    public function testNoValueToMatchIsAnEmptyCollectionAndNoQuery(): void
    {
        $collection = ColumnReadCollection::overEmptyKeyLazyStorage();

        $this->assertCount(0, $collection->whereColumnIs(ColumnReadObject::userId, null));
        $this->assertCount(0, $collection->whereColumnIn(ColumnReadObject::userId));
        $this->assertSame(0, ColumnReadEntity::queryCount());
    }

    /**
     * @throws HilosException When the collection refuses the read
     */
    public function testAnOwnerWithNoRowsIsAnEmptyCollectionRatherThanNothing(): void
    {
        $collection = ColumnReadCollection::overEmptyKeyLazyStorage();

        $matched = $collection->whereColumnIs(ColumnReadObject::userId, 99);

        $this->assertInstanceOf(ColumnReadCollection::class, $matched);
        $this->assertCount(0, $matched);
    }

    /**
     * Renders the ids a collection holds, so a case asserts the set rather than the plumbing.
     *
     * @param ColumnReadCollection $collection Collection to render
     * @return list<string> Item id strings in iteration order
     * @throws HilosException When the collection refuses to be walked
     */
    private function idsOf(ColumnReadCollection $collection): array
    {
        $ids = [];
        foreach ($collection as $item) {
            $ids[] = $item->getIdString();
        }

        return $ids;
    }
}

/**
 * Entity fixture whose table is an in-test row list, so the read is observable without a database.
 */
final class ColumnReadEntity extends Entity
{
    public const string _table = 'column_read_test';
    public const string _primary = 'id';
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const array _columns = [self::id, self::user_id];
    public const array _types = [self::id => 'integer', self::user_id => 'integer'];

    public ?int $id = null;
    public ?int $user_id = null;

    /** @var list<array<string, int>> Rows the fake table holds */
    private static array $tableRows = [];

    /** @var int How many times the fake table was queried */
    private static int $queryCount = 0;

    /**
     * @param list<array<string, int>> $tableRows Rows the fake table holds from now on
     */
    public static function setTableRows(array $tableRows): void
    {
        self::$tableRows = $tableRows;
        self::$queryCount = 0;
    }

    /**
     * @return int How many times the fake table was queried
     */
    public static function queryCount(): int
    {
        return self::$queryCount;
    }

    /**
     * Answers out of the fake table, supporting the two filter forms the column read builds.
     *
     * @param array<string, mixed>|string $filters Column => value pairs, or the batch IN clause
     * @param array<int, mixed>|string $filtersParam Values bound to the IN clause
     * @param array<string, string>|string $orderBy Unused here; the fake table has one order
     * @param int $limit Unused here; the fake table answers whole
     * @param int $offset Unused here; the fake table answers whole
     * @return EntityCollection Matching entities keyed by primary key
     */
    public static function get(
        array|string $filters = [],
        array|string $filtersParam = [],
        array|string $orderBy = [],
        int $limit = -1,
        int $offset = 0,
    ): EntityCollection {
        self::$queryCount++;

        $wanted = is_array($filters)
            ? array_values($filters)
            : (is_array($filtersParam) ? array_values($filtersParam) : []);

        $collection = EntityCollection::empty();
        foreach (self::$tableRows as $row) {
            if (!in_array($row[self::user_id], $wanted, true)) {
                continue;
            }
            $entity = new self();
            $entity->id = $row[self::id];
            $entity->user_id = $row[self::user_id];
            $entity->flushRelated();
            $collection->add($entity, (string)$row[self::id]);
        }

        return $collection;
    }
}

/**
 * Object fixture wrapping the column-read entity.
 *
 * @property-read ?int $id
 * @property-read ?int $userId
 */
final class ColumnReadObject extends Object_
{
    public const string ENTITY_CLASS = ColumnReadEntity::class;
    public const string id = 'id';
    public const string userId = 'userId';

    /**
     * @param string $property Property name (id, userId)
     * @return mixed Property value
     * @throws HilosException When the property is no field of this fixture
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            default => parent::__get($property),
        };
    }
}

/**
 * Object collection fixture, key-lazy exactly as the identities collection is.
 */
final class ColumnReadObjects extends Objects
{
    public const string OBJECT_CLASS = ColumnReadObject::class;
    public const string COLLECTION_KEY = 'column_read';
}

/**
 * View collection fixture over the key-lazy storage.
 */
final class ColumnReadCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = ColumnReadItem::class;
    public const string OBJECT_COLLECTION_CLASS = ColumnReadObjects::class;

    /**
     * @return self Collection over a key-lazy storage holding nothing yet
     * @throws HilosException When either collection refuses to be initialized
     */
    public static function overEmptyKeyLazyStorage(): self
    {
        $collection = self::init();
        $collection->setObjectCollection(ColumnReadObjects::initDB(Objects::LAZY_STRATEGY_KEY));

        return $collection;
    }
}

/**
 * View item fixture.
 *
 * @extends DbItem<ColumnReadObject>
 */
final class ColumnReadItem extends DbItem
{
}

/**
 * Object collection fixture nobody configured with an object class - the state a base collection
 * is in before a project gives it one.
 */
final class ColumnReadUnconfiguredObjects extends Objects
{
}

/**
 * View collection fixture over the unconfigured storage.
 */
final class ColumnReadUnconfiguredCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = ColumnReadItem::class;
    public const string OBJECT_COLLECTION_CLASS = ColumnReadUnconfiguredObjects::class;

    /**
     * @return self Collection over a storage that names no entity
     * @throws HilosException When either collection refuses to be initialized
     */
    public static function overUnconfiguredStorage(): self
    {
        $collection = self::init();
        $collection->setObjectCollection(ColumnReadUnconfiguredObjects::initDB(Objects::LAZY_STRATEGY_KEY));

        return $collection;
    }
}

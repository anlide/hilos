<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Database\Database;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\FilteredCollection;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Column reads use a full held set or query a partial lazy set, including cold reads (HIL-1533).
 * Only the Entity SQL boundary is doubled; production loading, hydration and View caching run.
 */
final class DbCollectionColumnReadTest extends TestCase
{
    protected function setUp(): void
    {
        SourceChangeBus::reset();
        ColumnReadEntity::setTableRows([
            ['id' => 1, 'user_id' => 6, 'code' => 'en'],
            ['id' => 2, 'user_id' => 6, 'code' => 'EN'],
            ['id' => 3, 'user_id' => 7, 'code' => ' en '],
        ]);
    }

    protected function tearDown(): void
    {
        Database::handlerEnd();
        SourceChangeBus::reset();
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

    /** @return iterable<string, array{int}> Every mount strategy */
    public static function strategies(): iterable
    {
        yield 'NONE' => [Objects::LAZY_STRATEGY_NONE];
        yield from self::lazyStrategies();
    }

    /** @return iterable<string, array{int}> Strategies whose initial set is partial */
    public static function lazyStrategies(): iterable
    {
        yield 'KEY' => [Objects::LAZY_STRATEGY_KEY];
        yield 'BATCH' => [Objects::LAZY_STRATEGY_BATCH];
        yield 'FULL_ON_ACCESS' => [Objects::LAZY_STRATEGY_FULL_ON_ACCESS];
    }

    /** @param bool $countFirst Whether count is the first entrance into cold memory */
    #[DataProvider('firstReaders')]
    public function testNoneLoadsOnceAndKeepsHeldInstances(bool $countFirst): void
    {
        $objects = ColumnReadObjects::initDB(Objects::LAZY_STRATEGY_NONE);
        $held = ColumnReadObject::fromEntity(ColumnReadEntity::fromRow(['id' => 1, 'user_id' => 8]));
        $objects->hold($held);
        $view = ColumnReadCollection::overStorage($objects);
        $item = $view[1];
        $this->expectSilentRead();

        if ($countFirst) {
            $this->assertSame(1, $objects->countByColumn(ColumnReadObject::userId, 6));
        } else {
            $this->assertSame([2], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 6)));
        }
        $this->assertSame(1, ColumnReadEntity::queryCount());
        $this->assertSame(1, ColumnReadEntity::$fullReads);
        $this->assertSame(0, ColumnReadEntity::$countReads);
        $this->assertTrue($objects->isAllLoaded());
        $this->assertSame($held, $objects[1]);
        $this->assertSame($item, $view[1]);
        $this->assertSame(['2'], $this->idsOf($view->whereColumnIs(ColumnReadEntity::user_id, 6)));

        // The fake table now disagrees with held memory: a second SQL read would answer no rows.
        ColumnReadEntity::setTableRows([]);
        $selection = $view->whereColumnIn(ColumnReadObject::userId, 8, '8', 8);
        $this->assertSame(['1'], $this->idsOf($selection));
        $this->assertSame($item, $selection[1]);
        $this->assertSame($held, $selection[1]->getObject());
        $this->assertSame(1, $objects->countByColumn(ColumnReadEntity::user_id, 8, '8', 8));
        $this->assertSame(0, $objects->countByColumn(ColumnReadEntity::user_id, 99));
        $this->assertSame(0, ColumnReadEntity::queryCount());
    }

    /** @return iterable<string, array{bool}> Both common read entrances */
    public static function firstReaders(): iterable
    {
        yield 'load first' => [false];
        yield 'count first' => [true];
    }

    /** @param int $strategy Lazy mount strategy */
    #[DataProvider('lazyStrategies')]
    public function testPartialLazyReadsSqlEvenWithMatchingRowsHeld(int $strategy): void
    {
        $objects = ColumnReadObjects::initDB($strategy);
        $held = ColumnReadObject::fromEntity(ColumnReadEntity::fromRow(['id' => 1, 'user_id' => 6]));
        $objects->hold($held);
        $this->expectSilentRead();

        $this->assertSame(2, $objects->countByColumn(ColumnReadObject::userId, 6));
        $this->assertSame([ColumnReadEntity::user_id => 6], ColumnReadEntity::$lastFilters);
        $this->assertSame([], ColumnReadEntity::$lastParams);
        $this->assertSame(1, ColumnReadEntity::$countReads);
        $this->assertSame(0, ColumnReadEntity::$hydratedRows);
        $this->assertSame([1], $objects->heldKeys());
        $this->assertFalse($objects->isAllLoaded());

        $this->assertSame([1, 2], iterator_to_array($objects->loadByColumn(ColumnReadEntity::user_id, 6, 6)));
        $this->assertSame('`user_id` IN (?, ?)', ColumnReadEntity::$lastFilters);
        $this->assertSame([6, 6], ColumnReadEntity::$lastParams);
        $this->assertSame($held, $objects[1]);
        $this->assertSame([1, 2], $objects->heldKeys());
        $this->assertFalse($objects->isAllLoaded());
        $this->assertSame(0, ColumnReadEntity::$fullReads);

        // Row 2 is held, but a partial read still asks SQL for membership.
        ColumnReadEntity::setTableRows([['id' => 3, 'user_id' => 6]]);
        $this->assertSame(1, $objects->countByColumn(ColumnReadObject::userId, 6, '6', 6));
        $this->assertSame('`user_id` IN (?, ?, ?)', ColumnReadEntity::$lastFilters);
        $this->assertSame([6, '6', 6], ColumnReadEntity::$lastParams);
        $this->assertSame(0, ColumnReadEntity::$hydratedRows);
        $this->assertSame([1, 2], $objects->heldKeys());
        $this->assertSame([3], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 6)));
        $this->assertSame(0, $objects->countByColumn(ColumnReadObject::userId, 99));
        $this->assertSame([], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 99)));
        $this->assertFalse($objects->isAllLoaded());
        $this->assertSame(0, ColumnReadEntity::$fullReads);
    }

    /** @param int $strategy Lazy mount strategy */
    #[DataProvider('lazyStrategies')]
    public function testPreloadedLazyCollectionsReadMemory(int $strategy): void
    {
        $objects = ColumnReadObjects::initDB($strategy);
        $objects->preloadAll();
        $this->assertSame(1, ColumnReadEntity::$fullReads);
        $view = ColumnReadCollection::overStorage($objects);
        $held = $objects[1];
        $item = $view[1];
        ColumnReadEntity::setTableRows([]);
        $this->expectSilentRead();

        $this->assertSame([1, 2], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, '6', 6, 6)));
        $this->assertSame(2, $objects->countByColumn(ColumnReadEntity::user_id, 6, '6'));
        $this->assertSame($held, $objects[1]);
        $this->assertSame($item, $view->whereColumnIs(ColumnReadEntity::user_id, 6)[1]);
        $this->assertSame([], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 99)));
        $this->assertSame(0, ColumnReadEntity::queryCount());
    }

    /** @param int $strategy Strategy whose ordinary entrance completes the set */
    #[DataProvider('selfLoadingStrategies')]
    public function testOrdinaryFullLoadAlsoEnablesMemoryReads(int $strategy): void
    {
        $objects = ColumnReadObjects::initDB($strategy);
        if ($strategy === Objects::LAZY_STRATEGY_BATCH) {
            $this->assertSame([1, 2, 3], $objects->keys());
        } else {
            $this->assertNotNull($objects[1]);
        }
        $this->assertTrue($objects->isAllLoaded());
        ColumnReadEntity::setTableRows([]);
        $this->assertSame([1, 2], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 6)));
        $this->assertSame(2, $objects->countByColumn(ColumnReadObject::userId, 6));
        $this->assertSame(0, ColumnReadEntity::queryCount());
    }

    /** @return iterable<string, array{int}> Strategies with an automatic full-load entrance */
    public static function selfLoadingStrategies(): iterable
    {
        yield 'BATCH walk' => [Objects::LAZY_STRATEGY_BATCH];
        yield 'FULL_ON_ACCESS key' => [Objects::LAZY_STRATEGY_FULL_ON_ACCESS];
    }

    public function testConcreteManualSetNeverExpandsToTheTable(): void
    {
        $objects = ColumnReadObjects::initEmpty();
        $this->assertSame([], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 6)));
        $this->assertSame(0, $objects->countByColumn(ColumnReadObject::userId, 6));
        $held = ColumnReadObject::fromEntity(ColumnReadEntity::fromRow(['id' => 2, 'user_id' => 8]));
        $objects->hold($held);
        $view = ColumnReadCollection::overStorage($objects);
        $this->expectSilentRead();

        $this->assertSame([], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 6)));
        $this->assertSame([2], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, '8', 8)));
        $this->assertSame(1, $objects->countByColumn(ColumnReadObject::userId, 8, 8));
        $this->assertSame($held, $view->whereColumnIs(ColumnReadObject::userId, 8)[2]->getObject());
        $this->assertSame([2], $objects->heldKeys());
        $this->assertFalse($objects->isAllLoaded());
        $this->assertSame(0, ColumnReadEntity::queryCount());
    }

    /** @param int $strategy Mount strategy */
    #[DataProvider('strategies')]
    public function testNoValuesOrNullDoNotLoadAnyStrategy(int $strategy): void
    {
        $objects = ColumnReadObjects::initDB($strategy);
        $view = ColumnReadCollection::overStorage($objects);
        $this->assertSame([], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId)));
        $this->assertSame(0, $objects->countByColumn(ColumnReadObject::userId));
        $this->assertCount(0, $view->whereColumnIs(ColumnReadObject::userId, null));
        $this->assertCount(0, $view->whereColumnIn(ColumnReadObject::userId));
        $this->assertSame([], $objects->heldKeys());
        $this->assertFalse($objects->isAllLoaded());
        $this->assertSame(0, ColumnReadEntity::queryCount());
    }

    /**
     * @param bool $count Whether to use the count entrance
     * @param bool $filtered Whether the collection is an unconfigured filtered wrapper
     */
    #[DataProvider('invalidReaders')]
    public function testColumnValidationPrecedesEmptyValues(bool $count, bool $filtered): void
    {
        $objects = ColumnReadObjects::initDB(Objects::LAZY_STRATEGY_NONE);
        $column = 'ownerId';
        if ($filtered) {
            $objects = new FilteredCollection($objects, []);
            $column = ColumnReadObject::userId;
        }
        try {
            if ($count) {
                $objects->countByColumn($column);
            } else {
                iterator_to_array($objects->loadByColumn($column));
            }
            $this->fail('An unknown column must be refused even without values.');
        } catch (InvalidArgumentException $failure) {
            $this->assertSame("database collection 'column_read' has no column '{$column}'", $failure->getMessage());
        }
        $this->assertSame(0, ColumnReadEntity::queryCount());
    }

    /** @return iterable<string, array{bool, bool}> Both readers over concrete and filtered stores */
    public static function invalidReaders(): iterable
    {
        yield 'load concrete' => [false, false];
        yield 'count concrete' => [true, false];
        yield 'load filtered' => [false, true];
        yield 'count filtered' => [true, true];
    }

    public function testMemoryMatchesDeclaredScalarTypesWithoutNormalizingCodes(): void
    {
        $objects = ColumnReadObjects::initEmpty();
        $objects->hold(ColumnReadObject::fromEntity(ColumnReadEntity::fromRow([
            'id' => 0, 'user_id' => 0, 'code' => '01', 'enabled' => false, 'rating' => 0.0,
        ])));
        $objects->hold(ColumnReadObject::fromEntity(ColumnReadEntity::fromRow([
            'id' => 1, 'user_id' => 6, 'code' => 'en', 'enabled' => true, 'rating' => 1.5,
        ])));
        $objects->hold(ColumnReadObject::fromEntity(ColumnReadEntity::fromRow([
            'id' => 2, 'user_id' => null, 'code' => 'EN', 'enabled' => false, 'rating' => 2.0,
        ])));
        $objects->hold(ColumnReadObject::fromEntity(ColumnReadEntity::fromRow([
            'id' => 3, 'user_id' => -6, 'code' => ' en ', 'enabled' => false, 'rating' => 3.0,
        ])));
        foreach ([
            [ColumnReadEntity::user_id, [6, '6'], [1]],
            [ColumnReadEntity::user_id, [0, '0'], [0]],
            [ColumnReadEntity::user_id, ['-6'], [3]],
            [ColumnReadEntity::user_id, ['invalid', '', '06', ' 6', '6.0', '6e0', PHP_INT_MAX . '0'], []],
            [ColumnReadEntity::code, ['en'], [1]],
            [ColumnReadEntity::code, ['EN'], [2]],
            [ColumnReadEntity::code, [' en '], [3]],
            [ColumnReadEntity::code, [1], []],
            [ColumnReadEntity::code, ['01'], [0]],
            [ColumnReadEntity::enabled, [1, '1'], [1]],
            [ColumnReadEntity::enabled, [0, '0'], [0, 2, 3]],
            [ColumnReadEntity::enabled, ['false', 'invalid', 2], []],
            [ColumnReadEntity::rating, ['1.5'], [1]],
            [ColumnReadEntity::rating, [2, '2'], [2]],
            [ColumnReadEntity::rating, ['invalid', '1e999'], []],
        ] as [$column, $values, $keys]) {
            $this->assertSame($keys, iterator_to_array($objects->loadByColumn($column, ...$values)), $column);
            $this->assertSame(count($keys), $objects->countByColumn($column, ...$values), $column);
        }
        $this->assertSame(0, ColumnReadEntity::queryCount());
    }

    public function testScalarAccessorReadsCurrentEntityColumnsBeforeSerialization(): void
    {
        $entity = EntityCountryName::fromRow([
            'id' => 9, 'country_id' => 6, 'language_id' => 2, 'locale_id' => null, 'name' => 'Poland', 'locked' => true,
        ]);
        $object = ObjectCountryName::fromEntity($entity);
        $this->expectSilentRead();
        $this->assertSame(6, $object->toArray()[ObjectCountryName::countryId]);
        $this->assertArrayNotHasKey(EntityCountryName::country_id, $object->toArray());
        $this->assertSame(6, $object->valueForColumn(EntityCountryName::country_id));
        $this->assertNull($object->valueForColumn(EntityCountryName::locale_id));
        $this->assertTrue($object->valueForColumn(EntityCountryName::locked));
        $this->assertSame('Poland', $object->valueForColumn(EntityCountryName::name));
        $object->countryId = 7;
        $this->assertSame(7, $object->valueForColumn(EntityCountryName::country_id));
        $this->assertSame(7, $entity->country_id);
        $this->assertSame(0, ColumnReadEntity::queryCount());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Entity '" . EntityCountryName::class . "' has no column 'countryId'");
        $object->valueForColumn(ObjectCountryName::countryId);
    }

    public function testScalarAccessorAndMatcherFollowEntitySubtypeLinks(): void
    {
        $objects = ColumnReadExtendedObjects::initEmpty();
        $object = ColumnReadExtendedObject::fromEntity(ColumnReadExtendedEntity::fromRow([
            'id' => 4, 'user_id' => 6, 'extra_code' => 'X',
        ]));
        $objects->hold($object);
        $this->assertSame('X', $object->valueForColumn(ColumnReadExtendedEntity::extra_code));
        $this->assertSame([4], iterator_to_array($objects->loadByColumn('extraCode', 'X')));
        $this->assertSame(1, $objects->countByColumn(ColumnReadExtendedEntity::extra_code, 'X'));
        $this->assertSame(0, $objects->countByColumn(ColumnReadExtendedEntity::extra_code, 'x'));
        $this->assertSame(0, ColumnReadEntity::queryCount());
    }

    public function testMemoryReadSeesRestoredObjectsAfterRollback(): void
    {
        $objects = ColumnReadObjects::initEmpty();
        $held = ColumnReadObject::fromEntity(ColumnReadEntity::fromRow(['id' => 1, 'user_id' => 6]));
        $objects->hold($held);
        Database::transactionStart();
        try {
            $objects->hold(ColumnReadObject::fromEntity(ColumnReadEntity::fromRow(['id' => 1, 'user_id' => 8])));
            $this->assertSame([1], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 8)));
            $this->assertSame(1, $objects->countByColumn(ColumnReadObject::userId, 8));
        } finally {
            Database::transactionRollback();
        }
        $this->assertSame($held, $objects[1]);
        $this->assertSame([1], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 6)));
        $this->assertSame(0, $objects->countByColumn(ColumnReadObject::userId, 8));
        $this->assertSame(0, ColumnReadEntity::queryCount());
    }

    /** @param int $strategy Mount strategy whose read may hydrate or load whole */
    #[DataProvider('strategies')]
    public function testRollbackForgetsRowsReadInsideTransaction(int $strategy): void
    {
        $objects = ColumnReadObjects::initDB($strategy);
        $held = ColumnReadObject::fromEntity(ColumnReadEntity::fromRow(['id' => 1, 'user_id' => 6]));
        $objects->hold($held);
        Database::transactionStart();
        try {
            $this->assertSame([1, 2], iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 6)));
        } finally {
            Database::transactionRollback();
        }
        $this->assertSame([1], $objects->heldKeys());
        $this->assertSame($held, $objects[1]);
        $this->assertFalse($objects->isAllLoaded());
        ColumnReadEntity::setTableRows([['id' => 3, 'user_id' => 6]]);
        $this->assertSame(
            $strategy === Objects::LAZY_STRATEGY_NONE ? [1, 3] : [3],
            iterator_to_array($objects->loadByColumn(ColumnReadObject::userId, 6)),
        );
        $this->assertSame(1, ColumnReadEntity::queryCount());
    }

    /** Installs an observer that refuses any source announcement made by the reads under test. */
    private function expectSilentRead(): void
    {
        $subscriber = $this->createMock(SourceChangeSubscriberInterface::class);
        $subscriber->expects($this->never())->method('onSourceChange');
        SourceChangeBus::subscribe($subscriber);
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
class ColumnReadEntity extends Entity
{
    public const string _table = 'column_read_test';
    public const string _primary = 'id';
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string code = 'code';
    public const string enabled = 'enabled';
    public const string rating = 'rating';
    public const array _columns = [self::id, self::user_id, self::code, self::enabled, self::rating];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::code => PhpType::STRING->value,
        self::enabled => PhpType::BOOLEAN->value,
        self::rating => PhpType::FLOAT->value,
    ];

    public ?int $id = null;
    public ?int $user_id = null;
    public string $code = 'unused';
    public bool $enabled = false;
    public float $rating = 0.0;

    public static int $fullReads = 0;
    public static int $countReads = 0;
    public static int $hydratedRows = 0;
    public static array|string $lastFilters = [];
    public static array|string $lastParams = [];

    /** @var list<array<string, mixed>> Rows the fake table holds */
    private static array $tableRows = [];

    /** @var int How many times the fake table was queried */
    private static int $queryCount = 0;

    /** @param list<array<string, mixed>> $tableRows Raw fake table rows; observation counters restart */
    public static function setTableRows(array $tableRows): void
    {
        self::$tableRows = $tableRows;
        self::$queryCount = 0;
        self::$fullReads = 0;
        self::$countReads = 0;
        self::$hydratedRows = 0;
        self::$lastFilters = [];
        self::$lastParams = [];
    }

    /** @return int How many times the fake table was queried */
    public static function queryCount(): int
    {
        return self::$queryCount;
    }

    /**
     * @param array<string, mixed>|string $filters Equality filters or the IN clause
     * @param array<int, mixed>|string $filtersParam Bound IN values
     * @param array<string, string>|string $orderBy Unused fake table order
     * @param int $limit Unused fake table limit
     * @param int $offset Unused fake table offset
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
        self::$lastFilters = $filters;
        self::$lastParams = $filtersParam;
        if ($filters === []) {
            self::$fullReads++;
        }
        $collection = EntityCollection::empty();
        foreach (self::$tableRows as $row) {
            if (self::rowMatches($row, $filters, $filtersParam)) {
                self::$hydratedRows++;
                $collection->add(static::fromRow($row), $row[self::id]);
            }
        }
        return $collection;
    }

    /** @return EntityCollection All rows at the Entity SQL boundary */
    public static function getAll(): EntityCollection
    {
        return static::get();
    }

    /**
     * @param mixed $id Primary key
     * @return ?static Row fetched through the same observed SQL boundary
     */
    public static function getById(mixed $id): ?static
    {
        return static::get([self::id => $id])->first();
    }

    /**
     * @param array<string, mixed>|string $filters Equality filters or the IN clause
     * @param array<int, mixed>|string $filtersParam Bound IN values
     * @return int Matching rows without constructing any Entity
     */
    public static function count(array|string $filters = [], array|string $filtersParam = []): int
    {
        self::$queryCount++;
        self::$countReads++;
        self::$lastFilters = $filters;
        self::$lastParams = $filtersParam;
        $count = 0;
        foreach (self::$tableRows as $row) {
            if (self::rowMatches($row, $filters, $filtersParam)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $row Raw fake database row
     * @param array<string, mixed>|string $filters Equality filters or an IN clause
     * @param array<int, mixed>|string $filtersParam Bound IN values
     * @return bool Whether this row matches canonical fixture values
     */
    private static function rowMatches(array $row, array|string $filters, array|string $filtersParam): bool
    {
        if ($filters === []) {
            return true;
        }
        if (is_array($filters)) {
            $column = array_key_first($filters);
            $wanted = array_values($filters);
        } else {
            if (preg_match('/^`([a-z_]+)` IN \(\?(?:, \?)*\)$/', $filters, $match) !== 1 || !is_array($filtersParam)) {
                throw new InvalidArgumentException('Unexpected fixture SQL condition: ' . $filters);
            }
            $column = $match[1];
            $wanted = $filtersParam;
        }
        foreach ($wanted as $value) {
            if ((string)$row[$column] === (string)$value) {
                return true;
            }
        }
        return false;
    }
}

/** @extends EntityCollection<ColumnReadEntity> */
final class ColumnReadEntities extends EntityCollection
{
    public const string ENTITY_CLASS = ColumnReadEntity::class;
}

/**
 * Object fixture wrapping the column-read entity.
 *
 * @property-read ?int $id
 * @property-read ?int $userId
 */
class ColumnReadObject extends Object_
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
class ColumnReadObjects extends Objects
{
    public const string OBJECT_CLASS = ColumnReadObject::class;
    public const string ENTITY_COLLECTION_CLASS = ColumnReadEntities::class;
    public const string COLLECTION_KEY = 'column_read';

    /** @param ColumnReadObject $object Row to retain through the production silent hydration seam */
    public function hold(ColumnReadObject $object): void
    {
        $this->hydrate($object->getIdString(), $object);
    }

    /** @return list<int|string> Held keys without causing loading or a completeness refusal */
    public function heldKeys(): array
    {
        return array_keys($this->objects);
    }
}

/** Entity subtype with an additional scalar, confined to this fixture. */
final class ColumnReadExtendedEntity extends ColumnReadEntity
{
    public const string extra_code = 'extra_code';
    public const array _columns = [...parent::_columns, self::extra_code];
    public const array _types = [...parent::_types, self::extra_code => PhpType::STRING->value];
    public string $extra_code = 'unused';
}

/** Object subtype whose static link selects the extended Entity. */
final class ColumnReadExtendedObject extends ColumnReadObject
{
    public const string ENTITY_CLASS = ColumnReadExtendedEntity::class;
}

/** Collection subtype whose static link selects the extended Object. */
final class ColumnReadExtendedObjects extends ColumnReadObjects
{
    public const string OBJECT_CLASS = ColumnReadExtendedObject::class;
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
        return self::overStorage(ColumnReadObjects::initDB(Objects::LAZY_STRATEGY_KEY));
    }

    /**
     * @param ColumnReadObjects $objects Production Object collection to wrap
     * @return self View over the supplied storage
     */
    public static function overStorage(ColumnReadObjects $objects): self
    {
        $collection = self::init();
        $collection->setObjectCollection($objects);
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

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Database\Database;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\Database\Exception\OrmForgetRefusedException;
use Hilos\Database\Object\FilteredCollection;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\Transaction\TransactionLevel;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A test reset is cold, but cannot revoke a collection's promise to answer for the whole table;
 * forgetting the holding is the entrance that does, back to what the mount promised.
 */
final class ObjectsForgetLoadedDataTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    private string|false $previousAppEnv = false;

    /** Installs a known test environment and a fresh fake table for each case. */
    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousAppEnv = getenv('APP_ENV');
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
        ForgetTestEntities::$ids = [1, 2];
        ForgetTestEntities::$wholeReads = 0;
    }

    /** Returns the environment and closes any transaction left by a failing assertion. */
    protected function tearDown(): void
    {
        Database::handlerEnd();
        Hilos::$env = $this->previousEnv;
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    /**
     * @return iterable<string, array{int, bool, string}> Strategy, full-load entrance and first read after reset
     */
    public static function fullCollections(): iterable
    {
        foreach ([Objects::LAZY_STRATEGY_NONE, Objects::LAZY_STRATEGY_KEY,
            Objects::LAZY_STRATEGY_BATCH, Objects::LAZY_STRATEGY_FULL_ON_ACCESS] as $strategy) {
            foreach ([false, true] as $explicitLoad) {
                foreach (['key', 'count', 'first', 'last', 'walk'] as $entrance) {
                    yield $strategy . '-' . (int)$explicitLoad . '-' . $entrance => [$strategy, $explicitLoad, $entrance];
                }
            }
        }
    }

    /**
     * @param int $strategy Declared loading strategy
     * @param bool $explicitLoad Whether fullness comes from loadAllFromDB instead of preload or the NONE promise
     * @param string $entrance First read after two resets
     * @throws HilosException When a collection read or reset refuses
     */
    #[DataProvider('fullCollections')]
    public function testFullnessSurvivesTwoColdResets(int $strategy, bool $explicitLoad, string $entrance): void
    {
        $objects = ForgetTestObjects::initDB($strategy);
        if ($explicitLoad) {
            $objects->loadAllFromDB();
        } elseif ($strategy === Objects::LAZY_STRATEGY_NONE) {
            $objects->first();
        } else {
            $objects->preloadAll();
        }
        $held = $objects[2];
        self::assertTrue($objects->isAllLoaded());
        self::assertSame(1, ForgetTestEntities::$wholeReads);
        ForgetTestEntities::$ids = [2, 3];

        $objects->forgetLoadedDataForTest();
        $objects->forgetLoadedDataForTest();

        self::assertFalse($objects->isAllLoaded());
        self::assertFalse(isset($objects[2]));
        self::assertSame(1, ForgetTestEntities::$wholeReads, 'Neither reset may read the table');
        self::assertSame(0, $objects->keyReads);
        self::assertSame(0, $objects->countReads);
        self::assertSame($strategy, $objects->getLazyStrategy());
        self::assertSame($strategy !== Objects::LAZY_STRATEGY_NONE, $objects->allowLazyLoading);
        self::assertNull($objects[null], 'An absent optional key must remain a non-reading entrance');
        self::assertSame(1, ForgetTestEntities::$wholeReads);

        match ($entrance) {
            'key' => self::assertSame('2', $objects[2]?->getIdString()),
            'count' => self::assertSame(2, $objects->count()),
            'first' => self::assertSame('2', $objects->first()?->getIdString()),
            'last' => self::assertSame('3', $objects->last()?->getIdString()),
            'walk' => self::assertSame([2, 3], array_keys(iterator_to_array($objects))),
        };

        self::assertTrue($objects->isAllLoaded());
        self::assertSame([2, 3], $objects->keys());
        self::assertSame(2, ForgetTestEntities::$wholeReads);
        self::assertSame(0, $objects->keyReads);
        self::assertSame(0, $objects->countReads);
        self::assertNotSame($held, $objects[2]);
    }

    /**
     * @return iterable<string, array{int}> Every native strategy before a full read
     */
    public static function strategies(): iterable
    {
        yield 'none' => [Objects::LAZY_STRATEGY_NONE];
        yield 'key' => [Objects::LAZY_STRATEGY_KEY];
        yield 'batch' => [Objects::LAZY_STRATEGY_BATCH];
        yield 'full-on-access' => [Objects::LAZY_STRATEGY_FULL_ON_ACCESS];
    }

    /**
     * @param int $strategy Native strategy to preserve
     * @throws HilosException When a collection read or reset refuses
     */
    #[DataProvider('strategies')]
    public function testAnIncompleteCollectionKeepsItsNativeStrategy(int $strategy): void
    {
        $objects = ForgetTestObjects::initDB($strategy);
        // KEY and BATCH hold a partial set; the other two remain unread.
        if ($strategy === Objects::LAZY_STRATEGY_KEY || $strategy === Objects::LAZY_STRATEGY_BATCH) {
            $objects[1];
        }
        $objects->forgetLoadedDataForTest();
        self::assertSame(0, ForgetTestEntities::$wholeReads);
        self::assertFalse(isset($objects[1]));

        self::assertSame('2', $objects[2]?->getIdString());
        if ($strategy === Objects::LAZY_STRATEGY_NONE || $strategy === Objects::LAZY_STRATEGY_FULL_ON_ACCESS) {
            self::assertTrue($objects->isAllLoaded());
            self::assertSame(1, ForgetTestEntities::$wholeReads);
            return;
        }

        self::assertFalse($objects->isAllLoaded());
        self::assertSame(2, $objects->keyReads);
        self::assertSame(2, $objects->count());
        self::assertSame(1, $objects->countReads);
        self::assertSame(0, ForgetTestEntities::$wholeReads);
        if ($strategy === Objects::LAZY_STRATEGY_KEY) {
            $this->expectException(CollectionNotFullyLoadedException::class);
            $objects->keys();
        } else {
            self::assertSame([2, 1], $objects->keys());
            self::assertSame(1, ForgetTestEntities::$wholeReads);
        }
    }

    /**
     * @param int $strategy Strategy the collection was mounted with
     * @throws HilosException When a collection read or reset refuses
     */
    #[DataProvider('strategies')]
    public function testForgettingTheHoldingReturnsEachStrategyToItsMount(int $strategy): void
    {
        $objects = ForgetTestObjects::initDB($strategy);
        $strategy === Objects::LAZY_STRATEGY_NONE ? $objects->first() : $objects->preloadAll();
        $held = $objects[2];
        self::assertTrue($objects->isAllLoaded());
        self::assertSame(1, ForgetTestEntities::$wholeReads);
        ForgetTestEntities::$ids = [2, 3];

        $objects->forgetHoldingForTest();
        $objects->forgetHoldingForTest();

        self::assertFalse($objects->isAllLoaded());
        self::assertSame(1, ForgetTestEntities::$wholeReads, 'Neither call may read the table');
        self::assertSame(0, $objects->keyReads);
        self::assertSame(0, $objects->countReads);
        self::assertSame($strategy, $objects->getLazyStrategy());
        self::assertSame($strategy !== Objects::LAZY_STRATEGY_NONE, $objects->allowLazyLoading);

        if ($strategy === Objects::LAZY_STRATEGY_NONE) {
            self::assertSame('3', $objects[3]?->getIdString());
            self::assertTrue($objects->isAllLoaded(), 'The promise of the mount itself stays');
            self::assertSame(2, ForgetTestEntities::$wholeReads);
            self::assertSame(0, $objects->keyReads);
            self::assertNotSame($held, $objects[2]);
            return;
        }
        if ($strategy === Objects::LAZY_STRATEGY_BATCH) {
            self::assertSame([2, 3], array_keys(iterator_to_array($objects)));
            self::assertTrue($objects->isAllLoaded());
            self::assertSame(2, ForgetTestEntities::$wholeReads);
            self::assertNotSame($held, $objects[2]);
            return;
        }

        try {
            iterator_to_array($objects);
            self::fail('A collection that no longer holds the set must refuse a walk');
        } catch (CollectionNotFullyLoadedException) {
            self::assertFalse($objects->isAllLoaded());
            self::assertSame(1, ForgetTestEntities::$wholeReads);
        }
        self::assertNotSame($held, $objects[2]);
        if ($strategy === Objects::LAZY_STRATEGY_KEY) {
            self::assertFalse($objects->isAllLoaded());
            self::assertSame(1, $objects->keyReads);
            self::assertSame(1, ForgetTestEntities::$wholeReads);
            return;
        }
        // FULL_ON_ACCESS answers its first key read the way its mount does: by reading whole.
        self::assertTrue($objects->isAllLoaded());
        self::assertSame(0, $objects->keyReads);
        self::assertSame(2, ForgetTestEntities::$wholeReads);
    }

    /**
     * @throws HilosException When a collection read or reset refuses
     */
    public function testForgettingTheHoldingDropsThePromiseAPlainResetKept(): void
    {
        $objects = ForgetTestObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $objects->preloadAll();
        $objects->forgetLoadedDataForTest();

        $objects->forgetHoldingForTest();

        try {
            iterator_to_array($objects);
            self::fail('The promise kept by the plain reset must not survive');
        } catch (CollectionNotFullyLoadedException) {
            self::assertFalse($objects->isAllLoaded());
        }
        self::assertSame(1, ForgetTestEntities::$wholeReads);
    }

    /**
     * @throws HilosException When a collection read or reset refuses
     */
    public function testForgettingTheHoldingGivesEmptyAndDetachedCollectionsNoPromise(): void
    {
        $empty = ForgetTestObjects::initEmpty();
        $empty->forgetHoldingForTest();
        self::assertSame(0, $empty->count());
        self::assertSame([], $empty->keys());
        self::assertNull($empty[1]);
        self::assertFalse($empty->allowLazyLoading);
        self::assertSame(0, ForgetTestEntities::$wholeReads);

        $source = ForgetTestObjects::initDB(Objects::LAZY_STRATEGY_NONE);
        $held = $source[1];
        $selection = new FilteredCollection($source, [1 => $held]);
        $selection->forgetHoldingForTest();
        $source->forgetHoldingForTest();
        self::assertSame($held, $selection[1]);
        self::assertSame(1, $selection->count());
        self::assertSame([1], $selection->keys());
        self::assertNull($selection[null]);
        self::assertSame(1, ForgetTestEntities::$wholeReads);
    }

    /**
     * @throws HilosException When a collection read or reset refuses
     */
    public function testEmptyAndDetachedCollectionsAcquireNoWholeTablePromise(): void
    {
        $empty = ForgetTestObjects::initEmpty();
        $empty->forgetLoadedDataForTest();
        self::assertSame(0, $empty->count());
        self::assertSame([], $empty->keys());
        self::assertNull($empty[1]);
        self::assertSame(0, ForgetTestEntities::$wholeReads);

        $source = ForgetTestObjects::initDB(Objects::LAZY_STRATEGY_NONE);
        $held = $source[1];
        $selection = new FilteredCollection($source, [1 => $held]);
        $source->forgetLoadedDataForTest();
        self::assertSame($held, $selection[1]);
        self::assertSame(1, $selection->count());
        self::assertNull($selection[null]);
        self::assertSame(1, ForgetTestEntities::$wholeReads);
    }

    /**
     * @return iterable<string, array{Closure(Objects): void}> Both direct test-only forget entrances
     */
    public static function directEntrances(): iterable
    {
        yield 'loaded data' => [static fn (Objects $objects) => $objects->forgetLoadedDataForTest()];
        yield 'holding' => [static fn (Objects $objects) => $objects->forgetHoldingForTest()];
    }

    /**
     * @return iterable<string, array{?string, Closure(Objects): void}> Refused environments, including no accessor, per entrance
     */
    public static function refusedEnvironments(): iterable
    {
        foreach (self::directEntrances() as $entrance => [$forget]) {
            yield 'production, ' . $entrance => ['prod', $forget];
            yield 'staging, ' . $entrance => ['staging', $forget];
            yield 'unknown, ' . $entrance => ['unrecognized', $forget];
            yield 'uninitialized, ' . $entrance => [null, $forget];
        }
    }

    /**
     * @param ?string $environment Environment to refuse
     * @param Closure(Objects): void $forget Entrance under test
     * @throws HilosException When a collection read refuses
     */
    #[DataProvider('refusedEnvironments')]
    public function testDirectResetCannotBypassTheEnvironmentGate(?string $environment, Closure $forget): void
    {
        $objects = ForgetTestObjects::initDB(Objects::LAZY_STRATEGY_NONE);
        $held = $objects[1];
        if ($environment === null) {
            Hilos::$env = null;
        } else {
            putenv('APP_ENV=' . $environment);
            Hilos::$env = new EnvAccessor();
        }

        try {
            $forget($objects);
            self::fail('The environment must refuse a direct reset');
        } catch (OrmForgetRefusedException $failure) {
            self::assertStringContainsString('non-production environment', $failure->getMessage());
        }
        self::assertSame($held, $objects[1]);
        self::assertTrue($objects->isAllLoaded());
        self::assertSame(1, ForgetTestEntities::$wholeReads);
    }

    /**
     * @param Closure(Objects): void $forget Entrance under test
     * @throws HilosException When the transaction or collection refuses
     */
    #[DataProvider('directEntrances')]
    public function testFailedTransactionLevelStillRefusesTheDirectReset(Closure $forget): void
    {
        $objects = ForgetTestObjects::initDB(Objects::LAZY_STRATEGY_NONE);
        $held = $objects[1];
        $indices = Database::getConfiguredIndices();
        $index = Database::getCurrentIndex();
        Database::transactionStart();
        /** @var array<int, list<TransactionLevel>> $levels Existing lazy transaction stacks */
        $levels = new ReflectionProperty(Database::class, 'transactions')->getValue();
        $levels[$index][0]->markFailed();

        self::assertTrue(Database::hasOpenTransactions());
        try {
            $forget($objects);
            self::fail('Even a failed transaction level must refuse the reset');
        } catch (OrmForgetRefusedException $failure) {
            self::assertStringContainsString('transaction is open', $failure->getMessage());
        }
        self::assertSame($levels, new ReflectionProperty(Database::class, 'transactions')->getValue());
        self::assertSame($index, Database::getCurrentIndex());
        self::assertSame($indices, Database::getConfiguredIndices());
        self::assertSame($held, $objects[1]);
        self::assertTrue($objects->isAllLoaded());

        Database::transactionRollback();
        self::assertFalse(Database::hasOpenTransactions());
        $forget($objects);
        self::assertFalse(isset($objects[1]));
    }
}

/** Single-column row for the isolated loader counters. */
final class ForgetTestEntity extends Entity
{
    public const string _table = 'forget_unit_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;

    /**
     * @param int $id Fake table key
     * @return self Loaded fake row
     */
    public static function withId(int $id): self
    {
        $entity = new self();
        $entity->id = $id;
        $entity->flushRelated();
        return $entity;
    }
}

/** Fake table shared only by the stores of the current case. */
final class ForgetTestEntities extends EntityCollection
{
    public const string ENTITY_CLASS = ForgetTestEntity::class;

    /** @var list<int> Current fake table keys */
    public static array $ids = [];

    public static int $wholeReads = 0;

    /** @return static Whole fake table, counted without SQL */
    public static function initFullDB(): static
    {
        self::$wholeReads++;
        $collection = new static();
        foreach (self::$ids as $id) {
            $collection->add(ForgetTestEntity::withId($id), (string)$id);
        }
        return $collection;
    }
}

/** Object wrapping the fake row. */
final class ForgetTestObject extends Object_
{
    public const string ENTITY_CLASS = ForgetTestEntity::class;
}

/** Keeps the real full-load implementation, replacing only the table and scalar reads. */
final class ForgetTestObjects extends Objects
{
    public const string OBJECT_CLASS = ForgetTestObject::class;
    public const string ENTITY_COLLECTION_CLASS = ForgetTestEntities::class;

    public int $keyReads = 0;
    public int $countReads = 0;

    /**
     * @param int|string|null $key Fake row key
     * @return ?ForgetTestObject Row in the fake table, or null
     */
    protected function lazyLoadObject(int|string|null $key): ?Object_
    {
        $this->keyReads++;
        return in_array($key, ForgetTestEntities::$ids, true)
            ? ForgetTestObject::fromEntity(ForgetTestEntity::withId($key))
            : null;
    }

    /** @return int Count of the fake table, with an independent counter */
    protected function lazyLoadCount(): int
    {
        $this->countReads++;
        return count(ForgetTestEntities::$ids);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;
use PHPUnit\Framework\TestCase;

/**
 * A collection declared full stays full across a re-read (HIL-670).
 *
 * Fullness comes from two different places and they are not the same fact. An eager collection
 * is full by STRATEGY, and a reset has always reloaded it. A lazy collection somebody called
 * {@see Objects::preloadAll()} on is full by DECLARATION: something asked for the whole set and
 * is now drawing a list from it.
 *
 * The reset used to remember only the first, which was harmless while the only thing that
 * triggered it was a restore. It stops being harmless once a peer link triggers it too: after a
 * reconnect the declared-full collection would come back lazy and quietly no longer whole, and
 * the list drawn from it would be missing rows with nothing anywhere to say so. Worse, the rule
 * that decides whether a row created on another node is taken reads exactly this claim - so the
 * collection would go on declining rows it is the one copy that needs them.
 */
final class ObjectsReHydrateFullnessTest extends TestCase
{
    /**
     * @throws HilosException When the collection refuses to be loaded
     */
    public function testACollectionDeclaredFullIsFullAgainAfterAReRead(): void
    {
        $collection = ReHydrateFullnessObjects::lazyWithTableRows([1, 2]);
        $collection->preloadAll();

        $collection->reHydrate();

        $this->assertTrue($collection->isAllLoaded());
    }

    /**
     * And it holds the table as it is NOW, which is the whole point of re-reading rather than
     * keeping what it had.
     *
     * @throws HilosException When the collection refuses to be loaded
     */
    public function testTheReReadPicksUpWhatTheTableHoldsNow(): void
    {
        $collection = ReHydrateFullnessObjects::lazyWithTableRows([1, 2]);
        $collection->preloadAll();

        $collection->setTableRows([1, 2, 3]);
        $collection->reHydrate();

        $this->assertTrue(isset($collection['3']));
    }

    /**
     * A lazy collection nobody declared full is left lazy, and that is not an oversight: it holds
     * the rows somebody asked for, so forgetting them and fetching each again on demand is both
     * correct and the cheaper answer.
     *
     * @throws HilosException When the collection refuses to be loaded
     */
    public function testAnOrdinaryLazyCollectionIsStillOnlyForgotten(): void
    {
        $collection = ReHydrateFullnessObjects::lazyWithTableRows([1, 2]);

        $collection->reHydrate();

        $this->assertFalse($collection->isAllLoaded());
        $this->assertSame(0, $collection->loadCount());
    }

    /**
     * @throws HilosException When the collection refuses to be loaded
     */
    public function testAnEagerCollectionReloadsAsItAlwaysDid(): void
    {
        $collection = ReHydrateFullnessObjects::eagerWithTableRows([1, 2]);
        $collection->loadAllFromDB();

        $collection->reHydrate();

        $this->assertTrue($collection->isAllLoaded());
    }
}

/**
 * Minimal single-column entity fixture for the fullness cases.
 */
final class ReHydrateFullnessEntity extends Entity
{
    public const string _table = 'rehydrate_fullness_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;

    /**
     * @param int $id Row id to carry
     * @return self Entity carrying that id
     */
    public static function withId(int $id): self
    {
        $entity = new self();
        $entity->id = $id;

        return $entity;
    }
}

/**
 * Minimal object fixture wrapping the fullness entity.
 */
final class ReHydrateFullnessObject extends Object_
{
    public const string ENTITY_CLASS = ReHydrateFullnessEntity::class;
}

/**
 * Object collection whose "table" is an in-test row list, so a re-read is observable without a
 * database, and which counts its reads so "was it reloaded at all" can be asserted.
 */
final class ReHydrateFullnessObjects extends Objects
{
    public const string OBJECT_CLASS = ReHydrateFullnessObject::class;

    /** @var list<int> Ids the fake table currently holds */
    private array $tableRows = [];

    /** @var int How many times the fake table was read */
    private int $loadCount = 0;

    /**
     * @param list<int> $tableRows Ids the fake table holds
     * @return self Lazy collection over that table
     */
    public static function lazyWithTableRows(array $tableRows): self
    {
        $collection = self::initDB(self::LAZY_STRATEGY_BATCH);
        $collection->tableRows = $tableRows;

        return $collection;
    }

    /**
     * @param list<int> $tableRows Ids the fake table holds
     * @return self Eager collection over that table
     */
    public static function eagerWithTableRows(array $tableRows): self
    {
        $collection = self::initDB(self::LAZY_STRATEGY_NONE);
        $collection->tableRows = $tableRows;

        return $collection;
    }

    /**
     * @param list<int> $tableRows Ids the fake table holds from now on
     */
    public function setTableRows(array $tableRows): void
    {
        $this->tableRows = $tableRows;
    }

    /**
     * @return int How many times the fake table was read
     */
    public function loadCount(): int
    {
        return $this->loadCount;
    }

    /**
     * Reads the fake table instead of the database.
     *
     * @throws DatabaseException Never here; the fake table is always reachable
     */
    public function loadAllFromDB(): void
    {
        $this->clearInMemory();
        $this->loadCount++;

        foreach ($this->tableRows as $id) {
            $this->hydrate((string)$id, ReHydrateFullnessObject::fromEntity(ReHydrateFullnessEntity::withId($id)));
        }

        $this->_allLoaded = true;
    }

    /**
     * Loads the whole fake table, which is what a lazy collection's full preload comes down to.
     *
     * @throws DatabaseException Never here; the fake table is always reachable
     */
    protected function lazyLoadAll(): void
    {
        $this->loadAllFromDB();
    }
}

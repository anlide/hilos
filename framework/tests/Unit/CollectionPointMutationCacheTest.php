<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Exception\View\Collection\DirectUnsetException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Collection\RtCollectionDirectUnsetException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Collection\RtCollection;
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
 */
final class CollectionPointMutationCacheTest extends TestCase
{
    private const string COLLECTION = 'unit-point-mutation-cache';

    private const string AGENT_ID = 'unit-point-mutation-host';

    private ?SignalRouter $previousSignalRouter = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        RtTruthSourceRegistry::register(self::COLLECTION, true, self::AGENT_ID);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(self::COLLECTION, self::AGENT_ID);
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

    public function testANullOffsetUnsetStaysSilentOnBothCollections(): void
    {
        $collection = $this->mounted();
        $dbCollection = PointMutationDbCollection::init();

        unset($collection[null]);
        unset($dbCollection[null]);

        $this->expectNotToPerformAssertions();
    }

    /**
     * Builds the collection the way the runtime context mounts it.
     *
     * @return PointMutationRtCollection Collection bound to a fresh state collection
     */
    private function mounted(): PointMutationRtCollection
    {
        // The state collection goes through a variable because setStateCollection() binds a
        // reference, exactly as the runtime context does when it represents a collection.
        $states = PointMutationRtStates::init();
        $collection = PointMutationRtCollection::init();
        $collection->setStateCollection($states);
        $collection->setCollectionName(self::COLLECTION);
        $collection->setActionsClass(PointMutationRtActions::class);

        return $collection;
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
 * Minimal DB collection used for the direct-unset contract.
 */
final class PointMutationDbCollection extends DbCollection
{
}

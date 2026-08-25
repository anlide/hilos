<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\OutboundRtSyncSubscriber;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use PHPUnit\Framework\TestCase;

/**
 * What a whole-collection hand-over owes the views of the node receiving it (HIL-674).
 *
 * The per-row road is pinned next door, by {@see RtSyncApplicatorViewRepairTest}: a row that
 * arrives one at a time goes through the collection, which announces, and a subscriber drops the
 * one wrapper the key no longer answers with. A snapshot takes neither of those steps. It empties
 * the store with a mass clear, which announces nothing on purpose - the mass road has a broadcast
 * of its own - so a view that had answered a key before the hand-over went on answering it
 * afterwards, out of a wrapper around a state the store no longer held.
 *
 * That is what these cases pin. A key the snapshot does not bring has to stop answering, and a
 * key it does bring has to answer with what the owner sent, not with what this node used to
 * believe.
 *
 * Every case seeds the wrapper cache with a read of its own before the hand-over it is about: a
 * key the view never answered for has nothing cached and would pass without any repair at all.
 */
final class RtSnapshotViewRepairTest extends TestCase
{
    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRuntime = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRuntime = Hilos::$rt;
        SourceChangeBus::reset();
    }

    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    /**
     * The hand-over itself, so that the two cases below fail for the reason they are about: rows
     * the view never shows would make an empty collection look like a repaired one.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testASnapshotIsVisibleThroughTheView(): void
    {
        $collection = $this->arrangeRuntime();

        RtSnapshot::replace(SnapshotRepairRtContext::COLLECTION, [
            'a' => ['id' => 'a', 'mark' => 'first'],
        ]);

        $this->assertSame('first', $collection['a']?->mark());
    }

    /**
     * The row the owner no longer has. Replacing is not merging, so a key the snapshot leaves out
     * is a key that is gone - and the view is the only place it could survive.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAKeyTheSnapshotDoesNotBringStopsAnsweringReads(): void
    {
        $collection = $this->arrangeRuntime();

        RtSnapshot::replace(SnapshotRepairRtContext::COLLECTION, [
            'a' => ['id' => 'a', 'mark' => 'first'],
            'b' => ['id' => 'b', 'mark' => 'second'],
        ]);
        // Seeds the wrapper cache the hand-over below has to clear.
        $this->assertNotNull($collection['a']);

        RtSnapshot::replace(SnapshotRepairRtContext::COLLECTION, [
            'b' => ['id' => 'b', 'mark' => 'second'],
        ]);

        $this->assertNull($collection['a']);
    }

    /**
     * The same key carried by both snapshots, which is the half a null check would miss: the view
     * answers, and answers with the row this node believed before the hand-over.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAKeyTheSnapshotBringsAnswersWithTheOwnersRow(): void
    {
        $collection = $this->arrangeRuntime();

        RtSnapshot::replace(SnapshotRepairRtContext::COLLECTION, [
            'a' => ['id' => 'a', 'mark' => 'first'],
        ]);
        // Seeds the wrapper cache around the row that is about to be replaced.
        $this->assertSame('first', $collection['a']?->mark());

        RtSnapshot::replace(SnapshotRepairRtContext::COLLECTION, [
            'a' => ['id' => 'a', 'mark' => 'second'],
        ]);

        $this->assertSame('second', $collection['a']?->mark());
    }

    /**
     * The scoped hand-over of an owner of named rows (HIL-589), in one case: the scope is brought
     * in line with what the frame carries, and the collection around it is left alone. Replacing
     * the whole collection here would delete the rows this node writes itself.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAScopedSnapshotReplacesItsScopeAndLeavesTheRestAsItWas(): void
    {
        $collection = $this->arrangeRuntime();
        RtSnapshot::replace(SnapshotRepairRtContext::COLLECTION, [
            'a' => ['id' => 'a', 'mark' => 'ours'],
            'b' => ['id' => 'b', 'mark' => 'stale'],
            'c' => ['id' => 'c', 'mark' => 'first'],
        ]);
        // Seeds the wrapper cache for every key the hand-over below touches or spares.
        $this->assertSame('ours', $collection['a']?->mark());
        $this->assertSame('stale', $collection['b']?->mark());
        $this->assertSame('first', $collection['c']?->mark());

        RtSnapshot::replaceScope(SnapshotRepairRtContext::COLLECTION, ['b', 'c'], [
            'c' => ['id' => 'c', 'mark' => 'second'],
        ]);

        $this->assertSame('ours', $collection['a']?->mark(), 'A row outside the scope is none of the frame\'s business');
        $this->assertNull($collection['b'], 'A row of the scope the frame does not carry is gone');
        $this->assertSame('second', $collection['c']?->mark());
    }

    /**
     * The scope is the whole authority of the frame: a row carried past it is dropped rather
     * than written, because nothing judged it - the two-owner question the daemon asks is asked
     * of the scope. Taken, such a row would let any frame overwrite a row this node owns by
     * simply not naming it.
     *
     * An empty scope is therefore a frame that speaks for nothing. The wire's other meaning of
     * an empty scope - "this is the whole collection" - is the caller's reading, and the caller
     * answers it by taking the {@see RtSnapshot::replace()} road instead.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAFrameThatCarriesARowPastItsScopeDoesNotWriteIt(): void
    {
        $collection = $this->arrangeRuntime();
        RtSnapshot::replace(SnapshotRepairRtContext::COLLECTION, ['a' => ['id' => 'a', 'mark' => 'ours']]);
        // Seeds the wrapper cache around the row the frame below reaches for without naming it.
        $this->assertSame('ours', $collection['a']?->mark());

        RtSnapshot::replaceScope(SnapshotRepairRtContext::COLLECTION, ['c'], [
            'a' => ['id' => 'a', 'mark' => 'stolen'],
            'c' => ['id' => 'c', 'mark' => 'theirs'],
        ]);

        $this->assertSame('ours', $collection['a']?->mark(), 'A row the frame never claimed stands as it was');
        $this->assertSame('theirs', $collection['c']?->mark());

        RtSnapshot::replaceScope(SnapshotRepairRtContext::COLLECTION, [], ['b' => ['id' => 'b', 'mark' => 'nobody']]);

        $this->assertNull($collection['b'], 'A scope of no rows speaks for no rows');
        $this->assertSame('ours', $collection['a']?->mark());
    }

    /**
     * Mounts the runtime a hand-over arrives into, exactly as facade init() leaves it.
     *
     * @return SnapshotRepairCollection Mounted view collection, empty
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    private function arrangeRuntime(): SnapshotRepairCollection
    {
        $context = new SnapshotRepairRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = $context;
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new OutboundRtSyncSubscriber());

        return $context->collection();
    }
}

/**
 * Runtime context mounting the one collection these cases hand over.
 */
final class SnapshotRepairRtContext extends RtContext
{
    public const string COLLECTION = 'unit-rt-snapshot-view-repair';

    /**
     * Registers the state collection and its view, as a project context does.
     *
     * No actions class is registered: a snapshot is written past the collection actions, and a
     * fixture that owned one would answer for a road this file does not test.
     *
     * @throws StateCollectionNotFoundException When the state collection was not registered first
     */
    public function configure(): void
    {
        $this->_stateCollections[self::COLLECTION] = SnapshotRepairStates::init();
        $this->setRepresent(self::COLLECTION, SnapshotRepairCollection::class);
    }

    /**
     * Hands back the mounted view without going through the magic getter.
     *
     * @return SnapshotRepairCollection Mounted view collection
     * @throws RtCollectionNotFoundException When configure() has not run
     */
    public function collection(): SnapshotRepairCollection
    {
        $collection = $this->getRtCollection(self::COLLECTION);

        return $collection instanceof SnapshotRepairCollection
            ? $collection
            : throw new RtCollectionNotFoundException('Snapshot-repair fixture collection is not mounted');
    }
}

/**
 * Minimal runtime state item carrying a mark that tells two rows of one key apart.
 */
final class SnapshotRepairState extends RtState
{
    private string $id = '';

    private string $mark = '';

    /**
     * @param string $id State ID
     * @param string $mark Value telling this row apart from another one of the same key
     * @return self Built state
     */
    public static function create(string $id, string $mark): self
    {
        $state = new self();
        $state->id = $id;
        $state->mark = $mark;

        return $state;
    }

    /**
     * @param array<string, mixed> $row Serialized state
     * @return static Restored state
     */
    public static function fromRow(array $row): static
    {
        return self::create((string)$row['id'], (string)$row['mark']);
    }

    /**
     * @return string State ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string Mark telling two rows of one key apart
     */
    public function getMark(): string
    {
        return $this->mark;
    }

    /**
     * @return array<string, mixed> Serialized state
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'mark' => $this->mark];
    }
}

/**
 * Minimal runtime state collection for the snapshot-repair fixtures.
 *
 * @extends RtStates<SnapshotRepairState>
 */
final class SnapshotRepairStates extends RtStates
{
    public const string STATE_CLASS = SnapshotRepairState::class;
}

/**
 * Minimal runtime view item reporting the mark of the state it wraps.
 *
 * @extends RtItem<SnapshotRepairState>
 */
final class SnapshotRepairItem extends RtItem
{
    /**
     * @return string Mark of the wrapped state
     */
    public function mark(): string
    {
        return $this->_state->getMark();
    }

    /**
     * @return array<string, mixed> Serialized state
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

/**
 * Minimal runtime view collection for the snapshot-repair fixtures.
 *
 * @extends RtCollection<SnapshotRepairItem, RtActions>
 */
final class SnapshotRepairCollection extends RtCollection
{
    /**
     * @param SnapshotRepairState $state State to wrap
     * @return RtItem View item for the state
     */
    protected function createRtItem(RtState &$state): RtItem
    {
        return new SnapshotRepairItem($state);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\OutboundRtSyncSubscriber;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateItemNotFoundException;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use PHPUnit\Framework\TestCase;

/**
 * What a sync applied from another process owes the views of this one (HIL-588).
 *
 * A row that arrives over the wire is written into the store by the applicator, and the view
 * that answers reads for that collection caches a wrapper per key. So the applicator carries two
 * obligations, and neither is visible from the store it writes to: the cached wrapper of a key
 * whose membership changed has to go, and nothing may be sent back out - a rebroadcast of what
 * was just received circles between processes forever.
 *
 * Both are held today by the applicator going through {@see RtStates::add()} and
 * {@see RtStates::remove()} inside `whileApplyingRemote()`, which announces the membership change
 * once and lets {@see ViewCacheSubscriber} repair the view while
 * {@see OutboundRtSyncSubscriber} stays quiet on a change this process only applied. Neither
 * obligation was pinned by anything: `SourceChangeBusTest` pins the bus with a hand-made
 * "applied remote" and never reaches the applicator, and {@see RtSyncApplicatorInvalidRowTest}
 * does reach it, but about a row a state refuses. This is that pin - the next edit of
 * {@see RtSyncApplicator} that writes into state directly, or drops the applied-remote marking,
 * turns red here rather than in a browser weeks later.
 *
 * A case about a wrapper going stale seeds the cache with a read of its own before the change it
 * is about, because a key the view never answered for has nothing cached and would pass without
 * any repair at all.
 */
final class RtSyncApplicatorViewRepairTest extends TestCase
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
     * Delivery itself, so that the two cases below fail for the reason they are about: a create
     * the view never shows would make an empty key look like a repaired one.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAnAppliedCreateIsVisibleThroughTheView(): void
    {
        $collection = $this->arrangeRuntime();

        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            ViewRepairRtContext::COLLECTION,
            'a',
            ['id' => 'a', 'mark' => 'first'],
        ));

        $this->assertSame('first', $collection['a']?->mark());
    }

    /**
     * A key the store lost must stop answering, which is the whole of what a stale wrapper gets
     * wrong: it keeps handing out a row nobody holds any more.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAnAppliedDeleteLeavesNothingBehindTheKey(): void
    {
        $collection = $this->arrangeRuntime();

        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            ViewRepairRtContext::COLLECTION,
            'a',
            ['id' => 'a', 'mark' => 'first'],
        ));
        // Seeds the wrapper cache the removal below has to clear.
        $this->assertNotNull($collection['a']);

        RtSyncApplicator::applyDeleted(new RtSyncDeletedSignalData(ViewRepairRtContext::COLLECTION, 'a'));

        $this->assertNull($collection['a']);
    }

    /**
     * The same key coming back with different content, which is the half a null check would miss:
     * the view answers, and answers with the row it no longer holds.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAReusedKeyAnswersWithTheNewRow(): void
    {
        $collection = $this->arrangeRuntime();

        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            ViewRepairRtContext::COLLECTION,
            'a',
            ['id' => 'a', 'mark' => 'first'],
        ));
        // Seeds the wrapper cache around the row that is about to be replaced.
        $this->assertSame('first', $collection['a']?->mark());

        RtSyncApplicator::applyDeleted(new RtSyncDeletedSignalData(ViewRepairRtContext::COLLECTION, 'a'));
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            ViewRepairRtContext::COLLECTION,
            'a',
            ['id' => 'a', 'mark' => 'second'],
        ));

        $this->assertSame('second', $collection['a']?->mark());
    }

    /**
     * The second obligation, and the one the view cannot show: an applied change repairs the
     * local views and stops there. Sent back out, it returns as the next process's inbound sync.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAnAppliedChangeIsNotSentBackOut(): void
    {
        $this->arrangeRuntime();

        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            ViewRepairRtContext::COLLECTION,
            'a',
            ['id' => 'a', 'mark' => 'first'],
        ));

        $this->assertSame([], $this->drainQueuedSignalTypes());

        RtSyncApplicator::applyDeleted(new RtSyncDeletedSignalData(ViewRepairRtContext::COLLECTION, 'a'));

        $this->assertSame([], $this->drainQueuedSignalTypes());
    }

    /**
     * The negative edge of the repair: a diff is written into the very row the wrapper points at,
     * so the wrapper is right where it stands and dropping it would only cost a rebuild.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAnAppliedDiffKeepsTheWrapperItAlreadyHanded(): void
    {
        $collection = $this->arrangeRuntime();

        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            ViewRepairRtContext::COLLECTION,
            'a',
            ['id' => 'a', 'mark' => 'first'],
        ));
        $before = $collection['a'];

        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            ViewRepairRtContext::COLLECTION,
            'a',
            ['mark' => 'second'],
        ));
        $after = $collection['a'];

        $this->assertSame($before, $after);
        $this->assertSame('second', $after?->mark());
        $this->assertSame([], $this->drainQueuedSignalTypes());
    }

    /**
     * The other road an applied diff can take, where there is no cache to go stale at all:
     * a context item alias builds its wrapper on every read. Pinned so that a cache appearing
     * there later cannot appear silently, without the repair this file is about.
     *
     * @throws HilosException When the fixture context cannot represent its own state item
     */
    public function testAStandaloneItemHasNoCacheToGoStale(): void
    {
        $this->arrangeStandaloneRuntime();

        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            ViewRepairStandaloneRtContext::SOLO,
            'solo',
            ['mark' => 'second'],
        ));

        $first = Hilos::$rt?->viewRepairSolo;
        $second = Hilos::$rt?->viewRepairSolo;

        $this->assertInstanceOf(ViewRepairItem::class, $first);
        $this->assertSame('second', $first->mark());
        $this->assertNotSame($first, $second);
    }

    /**
     * Mounts the runtime an inbound sync arrives into, exactly as facade init() leaves it.
     *
     * @return ViewRepairCollection Mounted view collection, empty
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    private function arrangeRuntime(): ViewRepairCollection
    {
        $context = new ViewRepairRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        $this->mountRuntime($context);

        return $context->collection();
    }

    /**
     * Mounts a runtime whose only source is a standalone item alias, with no collection at all -
     * the shape that sends the applicator down its standalone road.
     *
     * @throws HilosException When the fixture context cannot represent its own state item
     */
    private function arrangeStandaloneRuntime(): void
    {
        $context = new ViewRepairStandaloneRtContext();
        $context->configure();
        $this->mountRuntime($context);
    }

    /**
     * @param RtContext $context Configured fixture context to publish as the process runtime
     */
    private function mountRuntime(RtContext $context): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = $context;
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new OutboundRtSyncSubscriber());
    }

    /**
     * Empties the signal queue and names what was in it.
     *
     * @return array<int, string> Signal type of each queued signal, in the order they were queued
     */
    private function drainQueuedSignalTypes(): array
    {
        $types = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $types[] = $signal->signalType->getType();
        }

        return $types;
    }
}

/**
 * Runtime context mounting the one collection these cases sync into.
 */
final class ViewRepairRtContext extends RtContext
{
    public const string COLLECTION = 'unit-rt-sync-view-repair';

    /**
     * Registers the state collection and its view, as a project context does.
     *
     * No actions class is registered: an inbound sync is applied past the collection actions, and
     * a fixture that owned one would answer for a road this file does not test.
     *
     * @throws StateCollectionNotFoundException When the state collection was not registered first
     */
    public function configure(): void
    {
        $this->_stateCollections[self::COLLECTION] = ViewRepairStates::init();
        $this->setRepresent(self::COLLECTION, ViewRepairCollection::class);
    }

    /**
     * Hands back the mounted view without going through the magic getter.
     *
     * @return ViewRepairCollection Mounted view collection
     * @throws RtCollectionNotFoundException When configure() has not run
     */
    public function collection(): ViewRepairCollection
    {
        $collection = $this->getRtCollection(self::COLLECTION);

        return $collection instanceof ViewRepairCollection
            ? $collection
            : throw new RtCollectionNotFoundException('View-repair fixture collection is not mounted');
    }
}

/**
 * Runtime context whose only source is a standalone item alias, mounting no collection at all.
 *
 * @property-read ?ViewRepairItem $viewRepairSolo Standalone row the applicator writes its diff into
 */
final class ViewRepairStandaloneRtContext extends RtContext
{
    public const string SOLO = 'viewRepairSolo';

    /**
     * Registers the standalone row and its view.
     *
     * @throws StateItemNotFoundException When the state item alias was not registered first
     */
    public function configure(): void
    {
        $this->_stateItems[self::SOLO] = ViewRepairState::create('solo', 'first');
        $this->setRepresentItem(self::SOLO, ViewRepairItem::class);
    }
}

/**
 * Minimal runtime state item carrying a mark that tells two rows of one key apart.
 */
final class ViewRepairState extends RtState
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
     * Writes the one field of this fixture a diff can carry.
     *
     * @param array<string, mixed> $diff Changed fields => values
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists('mark', $diff)) {
            $this->mark = (string)$diff['mark'];
        }
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
 * Minimal runtime state collection for the view-repair fixtures.
 *
 * @extends RtStates<ViewRepairState>
 */
final class ViewRepairStates extends RtStates
{
    public const string STATE_CLASS = ViewRepairState::class;
}

/**
 * Minimal runtime view item reporting the mark of the state it wraps.
 *
 * @extends RtItem<ViewRepairState>
 */
final class ViewRepairItem extends RtItem
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
 * Minimal runtime view collection for the view-repair fixtures.
 *
 * @extends RtCollection<ViewRepairItem, RtActions>
 */
final class ViewRepairCollection extends RtCollection
{
    /**
     * @param ViewRepairState $state State to wrap
     * @return RtItem View item for the state
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new ViewRepairItem($state);
    }
}

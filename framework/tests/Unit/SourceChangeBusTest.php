<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\OutboundRtSyncSubscriber;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
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
 * The one channel a collection announces its membership changes on (HIL-603).
 *
 * The collection itself says that a row appeared or left, once, and everything that has to
 * follow is a subscriber to that: the view drops the wrapper it can no longer answer with, and
 * the outgoing sync tells the other processes. What is pinned here is the part that cannot be
 * seen from either subscriber alone - who is called in what order, what a change this process
 * only applied is allowed to cause, and what happens when there is nothing mounted to react.
 */
final class SourceChangeBusTest extends TestCase
{
    private const string AGENT_ID = 'unit-source-change-bus-host';

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRuntime = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRuntime = Hilos::$rt;
        SourceChangeBus::reset();
        RtTruthSourceRegistry::register(BusRtContext::COLLECTION, true, self::AGENT_ID);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(BusRtContext::COLLECTION, self::AGENT_ID);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    /**
     * Registration order is call order, which is the whole of how the framework guarantees that
     * the view is repaired before the outgoing sync collects its payload: facade init() subscribes
     * the two in that order and nothing else arranges them.
     */
    public function testSubscribersAreCalledInTheOrderTheyWereRegistered(): void
    {
        $seen = [];
        SourceChangeBus::subscribe(new BusLabelSubscriber($seen, 'first'));
        SourceChangeBus::subscribe(new BusLabelSubscriber($seen, 'second'));
        SourceChangeBus::subscribe(new BusLabelSubscriber($seen, 'third'));

        SourceChangeBus::publish(SourceChange::rtCreated(BusRtContext::COLLECTION, 'a', []));

        $this->assertSame(['first', 'second', 'third'], $seen);
    }

    /**
     * What the order above buys: by the time any later subscriber looks - the outgoing sync among
     * them - the view no longer answers for a key the store has lost.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testTheViewIsAlreadyRepairedWhenALaterSubscriberReadsIt(): void
    {
        $collection = $this->arrangeRuntime();
        $seen = [];
        SourceChangeBus::subscribe(new BusRecordingSubscriber(
            $seen,
            static fn (): bool => $collection['a'] !== null,
        ));

        $collection->actions->put('a', 'first');
        // Seeds the cache that the removal below has to clear.
        $this->assertNotNull($collection['a']);
        $collection->actions->drop('a');

        // Read on the create: the key already answers. Read on the removal: it already does not.
        $this->assertSame([true, false], $seen);
    }

    /**
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testALocalWriteIsSentOnToTheOtherProcesses(): void
    {
        $collection = $this->arrangeRuntime();

        $collection->actions->put('a', 'first');

        $this->assertSame([SignalConstants::RT_SYNC_CREATED], $this->drainQueuedSignalTypes());
    }

    /**
     * The write of an applied remote change must not travel back where it came from - a
     * rebroadcast echo circles between processes and never stops.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAnAppliedRemoteChangeIsNotSentBack(): void
    {
        $collection = $this->arrangeRuntime();

        SourceChangeBus::whileApplyingRemote(static function () use ($collection): void {
            $collection->actions->put('a', 'first');
        });

        $this->assertSame([], $this->drainQueuedSignalTypes());
        // The view is repaired all the same: a stale wrapper is wrong whoever wrote the row.
        $this->assertSame('first', $collection['a']?->mark());
    }

    /**
     * The provenance in force before the applied write is restored rather than assumed, so an
     * apply nested in another one does not hand the rest of the outer write to the broadcasters.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testTheProvenanceOfAnOuterWriteSurvivesANestedOne(): void
    {
        $this->arrangeRuntime();
        $seen = [];
        SourceChangeBus::subscribe(new BusProvenanceSubscriber($seen));

        SourceChangeBus::whileApplyingRemote(static function (): void {
            SourceChangeBus::whileApplyingRemote(static function (): void {
                SourceChangeBus::publish(SourceChange::rtCreated(BusRtContext::COLLECTION, 'a', []));
            });
            SourceChangeBus::publish(SourceChange::rtCreated(BusRtContext::COLLECTION, 'b', []));
        });
        SourceChangeBus::publish(SourceChange::rtCreated(BusRtContext::COLLECTION, 'c', []));

        $this->assertSame(
            [
                SourceChangeProvenance::AppliedRemote,
                SourceChangeProvenance::AppliedRemote,
                SourceChangeProvenance::LocalWrite,
            ],
            $seen,
        );
    }

    /**
     * A subscriber may mutate a collection of its own, and the announcement that mutation makes
     * has to reach every subscriber like any other rather than be dropped as re-entrant.
     */
    public function testAnAnnouncementMadeInsideAnAnnouncementIsDelivered(): void
    {
        Hilos::$sr = new SignalRouter();
        $seen = [];
        SourceChangeBus::subscribe(new BusEchoingSubscriber());
        SourceChangeBus::subscribe(new BusIdSubscriber($seen));

        SourceChangeBus::publish(SourceChange::rtCreated(BusRtContext::COLLECTION, 'a', []));

        $this->assertSame(['a-echo', 'a'], $seen);
    }

    /**
     * A store nobody mounted has no name to announce under, and the framework subscribers have
     * nothing to look up. Silence is the contract: this is what a collection built inside a unit
     * test or held by a process that never ran init() looks like.
     */
    public function testAnUnmountedStoreAnnouncesNothing(): void
    {
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new OutboundRtSyncSubscriber());
        $seen = [];
        SourceChangeBus::subscribe(new BusIdSubscriber($seen));

        $states = BusRtStates::init();
        $states->add(BusRtState::create('a', 'first'));
        $states->remove('a');

        $this->assertSame([], $seen);
        $this->assertSame([], $this->drainQueuedSignalTypes());
    }

    /**
     * With no signal router mounted the outgoing sync has nowhere to queue, which is a no-op and
     * not an error - the same one the direct queueing this replaced already was.
     *
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    public function testAWriteWithNoSignalRouterIsSilent(): void
    {
        $collection = $this->arrangeRuntime();
        Hilos::$sr = null;

        $collection->actions->put('a', 'first');

        $this->assertSame('first', $collection['a']?->mark());
    }

    /**
     * Mounts the runtime the framework subscribers read, exactly as facade init() leaves it.
     *
     * @return BusRtCollection Mounted view collection
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    private function arrangeRuntime(): BusRtCollection
    {
        Hilos::$sr = new SignalRouter();
        $context = new BusRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$rt = $context;
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new OutboundRtSyncSubscriber());

        return $context->collection();
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
 * Records whether the view already answers for the changed key, at the moment it is told.
 */
final class BusRecordingSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * @param array<int, bool> $seen Recording target, appended to on every change
     * @param callable(): bool $reads Reads the view for the key these cases mutate
     */
    public function __construct(private array &$seen, private $reads)
    {
    }

    /**
     * @param SourceChange $change Announced change, not read here
     * @param SourceChangeProvenance $provenance Announced provenance, not read here
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = ($this->reads)();
    }
}

/**
 * Records its own label whenever it is told of a change.
 */
final class BusLabelSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * @param array<int, string> $seen Recording target, appended to on every change
     * @param string $label Name this subscriber records itself under
     */
    public function __construct(private array &$seen, private readonly string $label)
    {
    }

    /**
     * @param SourceChange $change Announced change, not read here
     * @param SourceChangeProvenance $provenance Announced provenance, not read here
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $this->label;
    }
}

/**
 * Records the provenance each announcement carried.
 */
final class BusProvenanceSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * @param array<int, SourceChangeProvenance> $seen Recording target, appended to on every change
     */
    public function __construct(private array &$seen)
    {
    }

    /**
     * @param SourceChange $change Announced change, not read here
     * @param SourceChangeProvenance $provenance Provenance to record
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $provenance;
    }
}

/**
 * Records the id each announcement carried.
 */
final class BusIdSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * @param array<int, string> $seen Recording target, appended to on every change
     */
    public function __construct(private array &$seen)
    {
    }

    /**
     * @param SourceChange $change Announced change whose id is recorded
     * @param SourceChangeProvenance $provenance Announced provenance, not read here
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $change->sourceId;
    }
}

/**
 * Announces once more from inside an announcement, standing in for a subscriber that mutates.
 */
final class BusEchoingSubscriber implements SourceChangeSubscriberInterface
{
    private bool $echoed = false;

    /**
     * @param SourceChange $change Announced change the echo is derived from
     * @param SourceChangeProvenance $provenance Announced provenance, not read here
     * @throws HilosException Whatever a subscriber to the echoed announcement raises
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->echoed) {
            return;
        }
        $this->echoed = true;
        SourceChangeBus::publish(
            SourceChange::rtCreated($change->sourceKey, $change->sourceId . '-echo', []),
        );
    }
}

/**
 * Runtime context mounting the one collection these cases mutate.
 */
final class BusRtContext extends RtContext
{
    public const string COLLECTION = 'unit-source-change-bus';

    /**
     * Registers the state collection and its view, as a project context does.
     *
     * @throws StateCollectionNotFoundException When the state collection was not registered first
     */
    public function configure(): void
    {
        $this->_stateCollections[self::COLLECTION] = BusRtStates::init();
        $this->setRepresent(self::COLLECTION, BusRtCollection::class, BusRtActions::class);
    }

    /**
     * Hands back the mounted view without going through the magic getter.
     *
     * @return BusRtCollection Mounted view collection
     * @throws RtCollectionNotFoundException When configure() has not run
     */
    public function collection(): BusRtCollection
    {
        $collection = $this->getRtCollection(self::COLLECTION);

        return $collection instanceof BusRtCollection
            ? $collection
            : throw new RtCollectionNotFoundException('Source-change-bus fixture collection is not mounted');
    }
}

/**
 * Minimal runtime state item carrying a mark that tells two rows of one key apart.
 */
final class BusRtState extends RtState
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
 * Minimal runtime state collection for the bus fixtures.
 *
 * @extends RtStates<BusRtState>
 */
final class BusRtStates extends RtStates
{
    public const string STATE_CLASS = BusRtState::class;
}

/**
 * Minimal runtime view item reporting the mark of the state it wraps.
 *
 * @extends RtItem<BusRtState>
 */
final class BusRtItem extends RtItem
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
 * Minimal runtime view collection for the bus fixtures.
 *
 * @extends RtCollection<BusRtItem, BusRtActions>
 */
final class BusRtCollection extends RtCollection
{
    /**
     * @param BusRtState $state State to wrap
     * @return RtItem View item for the state
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new BusRtItem($state);
    }
}

/**
 * Exposes the protected point mutations of the base collection actions.
 *
 * @extends RtActions<BusRtItem, BusRtCollection, BusRtStates>
 */
final class BusRtActions extends RtActions
{
    /**
     * Adds one row, or replaces the row already standing under that key.
     *
     * @param string $id State ID to write
     * @param string $mark Value telling this row apart from an earlier one of the same key
     * @throws HilosException On a truth-source refusal or a subscriber to the announcement
     */
    public function put(string $id, string $mark): void
    {
        $this->addStateToCollection(BusRtState::create($id, $mark));
    }

    /**
     * Removes one row by key.
     *
     * @param string $id State ID to remove
     * @throws HilosException On a truth-source refusal or a subscriber to the announcement
     */
    public function drop(string $id): void
    {
        $this->removeStateFromCollection($id);
    }
}

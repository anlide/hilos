<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Runtime\DTO\RtStalenessSignalData;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Socket\Worker\DTO\WorkerRtStalenessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncStreamDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * How a worker learns that part of its RT copy has stopped being kept up to date (HIL-711).
 *
 * Only the master sees a peer link open or close, and every worker holds its own copy of what
 * that link was keeping current — so the verdict travels rather than being recomputed on each
 * side. These cases pin the trip: the frame survives the wire, the worker writes it into the
 * store every reader of that process asks, and a worker that came up during a break learns the
 * same thing from its snapshot instead.
 */
final class WorkerRtStalenessDeliveryTest extends TestCase
{
    /** @var string RT collection these cases freeze rows of */
    public const string COLLECTION = 'workerStatuses';

    /** @var float Microtime the rows froze at */
    private const float FROZE_AT = 1000.5;

    /** @var int Number of the worker's interest report that named the collection, which the master's snapshot answers */
    private const int REPORT = 1;

    /** @var int The most RT passes a case runs for what one hand-over of parts owes */
    private const int MAX_OWED_PASSES = 16;

    /** @var string A second collection the page under test also reads */
    private const string OTHER_COLLECTION = 'jobs';

    /** @var float Microtime the second collection froze at, later than the first */
    private const float FROZE_LATER = 2000.5;

    private const string SNAPSHOT_ROW = 'row-1';
    private const int MAX_MANUAL_PASSES = 1000;

    /** @var string Connection the page under test is subscribed on */
    private const string ACCEPT_KEY = 'unit-rt-staleness-accept-key';

    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        // The verdict is addressed by interest, so the cases stand where interest is answered.
        SourceInterestRegistry::readsWhatIsDelivered();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        RtStaleness::reset();
        RtOrdinaryAdmission::reset();
        SourceInterestRegistry::readsWhatItMounts();
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page(self::ACCEPT_KEY));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent('unit_rt_staleness:1'));
        Hilos::$sr = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    /**
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheStalenessFrameSurvivesTheWire(): void
    {
        $dto = new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1', 'row-2'], self::FROZE_AT);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerRtStalenessMessageDTO::class, $parsed);
        $this->assertSame(self::COLLECTION, $parsed->collectionKey);
        $this->assertSame(['row-1', 'row-2'], $parsed->stateIds);
        $this->assertSame(self::FROZE_AT, $parsed->since);
    }

    /**
     * A missing moment is the mark being LIFTED, which is why one frame carries both directions:
     * a second message type would repeat every field this one already has.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testAFrameWithNoMomentIsTheMarkBeingLifted(): void
    {
        $dto = new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], null);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerRtStalenessMessageDTO::class, $parsed);
        $this->assertNull($parsed->since);
    }

    /**
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheWorkerWritesTheFrozenRowsIntoItsOwnStore(): void
    {
        $worker = new WorkerRtStalenessDeliveryTestManager();

        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], self::FROZE_AT),
        );

        $this->assertSame(self::FROZE_AT, RtStaleness::staleSince(self::COLLECTION, 'row-1'));
        $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-2'));
    }

    /**
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheWorkerLiftsTheMarkOnAFrameWithNoMoment(): void
    {
        $worker = new WorkerRtStalenessDeliveryTestManager();
        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], self::FROZE_AT),
        );

        $worker->handleDaemonMessage(new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], null));

        $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-1'));
    }

    /**
     * The snapshot is the whole truth about the collection, freshness included — so it REPLACES
     * what was held rather than adding to it. A worker whose last reader of a collection went
     * away stops being sent the thaw frames, since those are addressed by interest; a mark from
     * before would otherwise survive a snapshot saying the rows are current.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testASnapshotReplacesTheMarksTheWorkerAlreadyHeld(): void
    {
        // The master sends a snapshot to a worker that reads its collection, and to no other.
        SourceInterestRegistry::register(SourceChange::KIND_RT, self::COLLECTION, SourceConsumer::agent('unit_rt_staleness:1'));
        $worker = new WorkerRtStalenessDeliveryTestManager();
        self::reported($worker);
        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], self::FROZE_AT),
        );
        $master = $this->master($parts);

        $master->sendSnapshot(self::COLLECTION, RtSnapshotScope::whole(), announcement: self::REPORT);
        $this->deliver($worker, $parts);

        $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-1'));
    }

    /** @return array<string, array{bool, bool, bool}> Deferred apply, a new freeze epoch, and whether the sender includes the freeze */
    public static function snapshotFreezeBoundaries(): array
    {
        return [
            'normal publication' => [false, false, true],
            'deferred publication' => [true, false, true],
            'new epoch' => [false, true, true],
            'new epoch with deferred publication' => [true, true, true],
            'old behavior control' => [false, false, false],
            'deferred old behavior control' => [true, false, false],
            'old epoch control' => [false, true, false],
            'deferred old epoch control' => [true, true, false],
        ];
    }

    /**
     * The freeze precedes the snapshot's head. Pausing inside the worker's pass makes that boundary
     * deterministic without another process or a real-clock delay.
     *
     * @param bool $deferred Whether incoming decoding/publication is advanced on explicit allowances
     * @param bool $renewed Whether the cut held an older mark which was subsequently thawed
     * @param bool $included Whether the sender uses the new path; false demonstrates the original loss
     */
    #[DataProvider('snapshotFreezeBoundaries')]
    public function testASnapshotNotYetStartedRetainsTheFreezeItOvertakes(bool $deferred, bool $renewed, bool $included): void
    {
        $previousRt = Hilos::$rt;
        Hilos::$rt = null;
        $worker = new WorkerRtStalenessDeliveryTestManager();
        $guard = new ReflectionProperty(WorkerManager::class, 'rtPassRunning');
        try {
            SourceInterestRegistry::register(SourceChange::KIND_RT, self::COLLECTION, SourceConsumer::agent('unit_rt_staleness:1'));
            self::reported($worker);
            $master = $this->master($parts, static function (): void {});
            $master->sendSnapshot(self::COLLECTION, RtSnapshotScope::whole(),
                staleRows: $renewed ? [self::SNAPSHOT_ROW => self::FROZE_AT] : [], announcement: self::REPORT);
            $this->assertSame([], $parts, 'The snapshot is still queued before its head');
            if ($renewed) {
                $worker->handleDaemonMessage(new WorkerRtStalenessMessageDTO(self::COLLECTION, [self::SNAPSHOT_ROW], self::FROZE_AT));
                $worker->handleDaemonMessage(new WorkerRtStalenessMessageDTO(self::COLLECTION, [self::SNAPSHOT_ROW], null));
                $this->assertNull(RtStaleness::staleSince(self::COLLECTION, self::SNAPSHOT_ROW));
            }
            if ($included) {
                $master->includeStalenessInUnstartedSnapshots(self::COLLECTION, [self::SNAPSHOT_ROW => self::FROZE_LATER]);
            }
            // Exactly one new freeze reaches the real manager before any part of the snapshot.
            $worker->handleDaemonMessage(new WorkerRtStalenessMessageDTO(self::COLLECTION, [self::SNAPSHOT_ROW], self::FROZE_LATER));
            $this->assertSame(self::FROZE_LATER, RtStaleness::staleSince(self::COLLECTION, self::SNAPSHOT_ROW));
            $master->pump(new RtWorkBudget());
            $this->assertCount(1, $parts, 'No separate head frame or extra credit round trip is needed');
            if ($deferred) {
                $guard->setValue($worker, true);
                foreach (array_splice($parts, 0) as $part) {
                    $worker->handleDaemonMessage(new WorkerRtSyncStreamDTO($part));
                }
                $stream = new ReflectionMethod(WorkerManager::class, 'rtSyncStream')->invoke($worker);
                $stream->pump(new RtWorkBudget(1, 64));
                $this->assertFalse($stream->isIdle(), 'The RT pass is held before publication');
                $this->assertSame(self::FROZE_LATER, RtStaleness::staleSince(self::COLLECTION, self::SNAPSHOT_ROW));
                for ($pass = 0; $pass < self::MAX_MANUAL_PASSES && !$stream->isIdle(); $pass++) {
                    $stream->pump(new RtWorkBudget(4, 64));
                }
                $this->assertTrue($stream->isIdle(), 'Explicit portions finish the worker publication');
                $guard->setValue($worker, false);
            }
            $this->deliver($worker, $parts);
            $expected = $included ? self::FROZE_LATER : ($renewed ? self::FROZE_AT : null);
            $this->assertSame($expected, RtStaleness::staleSince(self::COLLECTION, self::SNAPSHOT_ROW),
                'Only the enriched snapshot preserves the new freeze; no repeat arrives after publication');
            $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, self::COLLECTION));
            $worker->handleDaemonMessage(new WorkerRtStalenessMessageDTO(self::COLLECTION, [self::SNAPSHOT_ROW], null));
            $this->assertNull(RtStaleness::staleSince(self::COLLECTION, self::SNAPSHOT_ROW), 'An ordinary subsequent thaw still clears the mark');
        } finally {
            $guard->setValue($worker, false);
            Hilos::$rt = $previousRt;
        }
    }

    /**
     * A row whose streamed operation this worker refused is held, and its copy here is frozen
     * whatever the master says of its own: the master's thaw lifts the rows it names except that
     * one, and the snapshot that gives it a base again lifts it too - published behind the refused
     * operation on the same stream.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testARowTheWorkersStreamHoldsStaysFrozenThroughTheMastersThawUntilASnapshot(): void
    {
        // The master sends a snapshot to a worker that reads its collection, and to no other.
        SourceInterestRegistry::register(SourceChange::KIND_RT, self::COLLECTION, SourceConsumer::agent('unit_rt_staleness:1'));
        $worker = new WorkerRtStalenessDeliveryTestManager();
        self::reported($worker);
        $worker->handleDaemonMessage(new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-2'], self::FROZE_AT));
        $master = $this->master($parts);
        // An operation naming a node is refused by a worker at once, so its row is held.
        $master->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['id' => 'row-1']), 'unit-peer-node');
        $this->assertCount(1, $parts);

        $this->deliver($worker, $parts);
        $this->assertNotNull(RtStaleness::staleSince(self::COLLECTION, 'row-1'), 'The held row is frozen');

        $worker->handleDaemonMessage(new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1', 'row-2'], null));
        $this->assertNotNull(RtStaleness::staleSince(self::COLLECTION, 'row-1'), 'The master\'s thaw does not lift a held row');
        $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-2'));

        $master->sendSnapshot(self::COLLECTION, RtSnapshotScope::whole(), announcement: self::REPORT);
        $this->deliver($worker, $parts);
        $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-1'));
    }

    /**
     * The mark is addressed to the pages that read the collection, and to nothing else: it is
     * about what a reader is being shown, and one burning on a screen where everything is in
     * order is one readers stop noticing (Design D7).
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testThePageReadingTheCollectionIsToldWhereItStands(): void
    {
        $worker = new WorkerRtStalenessDeliveryTestManager();
        $this->pageReads(self::COLLECTION);

        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], self::FROZE_AT),
        );

        $signal = $this->queuedStaleness();
        $this->assertNotNull($signal);
        $this->assertTrue($signal->stale);
    }

    /**
     * An agent reading the same collection is passed over: it has no connection to address, and
     * it asks {@see RtStaleness::staleSince()} where it needs the answer.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testAnAgentReadingTheCollectionIsNotSentAFrame(): void
    {
        $worker = new WorkerRtStalenessDeliveryTestManager();
        SourceInterestRegistry::register(
            SourceChange::KIND_RT,
            self::COLLECTION,
            SourceConsumer::agent('unit_rt_staleness:1'),
        );

        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], self::FROZE_AT),
        );

        $this->assertNull($this->queuedStaleness());
    }

    /**
     * The verdict is over the WHOLE page and not over the collection that moved: a page reading
     * two of them stays affected while either one is frozen, and the moment it is told is the
     * earliest among them - how out of date the worst of what it shows may be.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testThePageIsAnsweredOverEverythingItReads(): void
    {
        $worker = new WorkerRtStalenessDeliveryTestManager();
        $this->pageReads(self::COLLECTION, self::OTHER_COLLECTION);
        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], self::FROZE_AT),
        );

        // The later of the two moves second, so an answer taken from the moving collection alone
        // would name it - and would understate how old the page is.
        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::OTHER_COLLECTION, ['row-9'], self::FROZE_LATER),
        );

        $signal = $this->lastQueuedStaleness();
        $this->assertNotNull($signal);
        $this->assertSame((int)round(self::FROZE_AT * TimeConstants::MS_PER_SECOND), $signal->since);
    }

    /**
     * And the lift is the same answer the other way round: a page whose second collection is
     * still frozen stays marked, so the thaw of the first one cannot clear its screen early.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testThePageStaysMarkedWhileAnythingElseItReadsIsFrozen(): void
    {
        $worker = new WorkerRtStalenessDeliveryTestManager();
        $this->pageReads(self::COLLECTION, self::OTHER_COLLECTION);
        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], self::FROZE_AT),
        );
        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::OTHER_COLLECTION, ['row-9'], self::FROZE_LATER),
        );

        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], null),
        );

        $signal = $this->lastQueuedStaleness();
        $this->assertNotNull($signal);
        $this->assertTrue($signal->stale);
        $this->assertSame((int)round(self::FROZE_LATER * TimeConstants::MS_PER_SECOND), $signal->since);
    }

    /**
     * A page is told about a collection its topology names nowhere, when the page declares it
     * itself. This is what {@see AbstractPage::READS_RT} is for, and the logs section is its
     * first reader: that section draws its picture out of the cluster log mirror, so no table of
     * its own is built from the collection whose freeze it has to show (HIL-876).
     *
     * @throws InvalidArgumentException When the staleness signal cannot be named
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testAPageIsToldAboutTheCollectionOnlyItsOwnDeclarationNames(): void
    {
        $worker = new WorkerRtStalenessDeliveryTestManager();
        WorkerRtStalenessDeliveryTestHilos::initBrowser(new WorkerRtStalenessDeliveryTestBrowserContext());
        $this->pageSubscribes($worker, WorkerRtStalenessDeliveryTestPage::PAGE);

        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], self::FROZE_AT),
        );

        $signal = $this->lastQueuedStaleness();
        $this->assertNotNull($signal);
        $this->assertTrue($signal->stale);
    }

    /**
     * Takes up what one page reads, as its subscription would, and on the same path.
     *
     * @param WorkerManager $worker Worker under test
     * @param string $page Page being subscribed to
     */
    private function pageSubscribes(WorkerManager $worker, string $page): void
    {
        $takeUp = Closure::bind(
            static function (WorkerManager $manager, string $pageName, string $acceptKey): void {
                $manager->takeUpPageSources($pageName, $acceptKey);
            },
            null,
            WorkerManager::class,
        );

        $takeUp($worker, $page, self::ACCEPT_KEY);
    }

    /**
     * The worker named the collection in its interest report, and the answer to it came: what a
     * worker the master sends snapshots to stands for in these cases (HIL-1817).
     *
     * @param WorkerManager $worker Worker reading the collection
     */
    private static function reported(WorkerManager $worker): void
    {
        new ReflectionProperty(WorkerManager::class, 'rtInterestNumber')->setValue($worker, self::REPORT);
        new ReflectionProperty(WorkerManager::class, 'rtInterestAddedAt')->setValue($worker, [self::COLLECTION => self::REPORT]);
        new ReflectionProperty(WorkerManager::class, 'rtInterestAnswered')->setValue($worker, [self::COLLECTION => true]);
    }

    /**
     * @param list<RtSyncStreamFrame>|null $parts Takes the parts the master's end writes, in order
     * @param ?Closure(): void $wake Optional explicit pump scheduling, null to pump immediately
     * @return RtSyncStreamChannel The master's end of the worker's RT stream
     */
    private function master(?array &$parts, ?Closure $wake = null): RtSyncStreamChannel
    {
        $parts = [];

        return new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $frame) use (&$parts): void {
                $parts[] = $frame;
            },
            static fn (): null => null,
            $wake,
        );
    }

    /**
     * Hands the worker every part the master's end wrote and has not handed yet, then runs the RT
     * passes the worker still owes for them. A pass ends at its wall-clock budget, so one pass under
     * a loaded box may stop short of a publication an idle one reaches; the loop takes what the
     * worker's next loop iterations would.
     *
     * @param WorkerRtStalenessDeliveryTestManager $worker Worker reading the stream
     * @param list<RtSyncStreamFrame> $parts Parts written so far; emptied as they are handed over
     * @throws InvalidFormatException When a frame is not the object its DTO needs
     */
    private function deliver(WorkerRtStalenessDeliveryTestManager $worker, array &$parts): void
    {
        foreach (array_splice($parts, 0) as $part) {
            $worker->handleDaemonMessage(new WorkerRtSyncStreamDTO($part));
        }
        $pass = new ReflectionMethod(WorkerManager::class, 'runRtPass');
        $owes = new ReflectionMethod(WorkerManager::class, 'hasRtPassWork');
        for ($passes = 0; $passes < self::MAX_OWED_PASSES && $owes->invoke($worker); $passes++) {
            $pass->invoke($worker);
        }
        $this->assertFalse($owes->invoke($worker), 'The passes the worker owed are run');
    }

    /**
     * Records what the page under test reads, as its subscription would.
     *
     * @param string ...$collectionKeys RT collections that page reads
     */
    private function pageReads(string ...$collectionKeys): void
    {
        foreach ($collectionKeys as $collectionKey) {
            SourceInterestRegistry::register(
                SourceChange::KIND_RT,
                $collectionKey,
                SourceConsumer::page(self::ACCEPT_KEY),
            );
        }
    }

    /**
     * The first frozen-replica verdict waiting in the queue, addressed to the page under test.
     *
     * @return ?RtStalenessSignalData Payload of that signal, or null when none was queued
     */
    private function queuedStaleness(): ?RtStalenessSignalData
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $this->stalenessPayloadOf($signal);
            if ($payload !== null) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * The LAST such verdict, for the cases that drive more than one move.
     *
     * @return ?RtStalenessSignalData Payload of the newest such signal, or null when none was queued
     */
    private function lastQueuedStaleness(): ?RtStalenessSignalData
    {
        $last = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $this->stalenessPayloadOf($signal);
            if ($payload !== null) {
                $last = $payload;
            }
        }

        return $last;
    }

    /**
     * @param SignalDTO $signal Signal taken off the queue
     * @return ?RtStalenessSignalData Its payload when it is a verdict addressed to the page under
     *     test, null otherwise
     */
    private function stalenessPayloadOf(SignalDTO $signal): ?RtStalenessSignalData
    {
        if ($signal->signalName->getName() !== SignalTypeConstants::RT_STALENESS) {
            return null;
        }
        $data = $signal->data;
        if (!$data instanceof WebSocketSignalData || $data->targetAcceptKey !== self::ACCEPT_KEY) {
            return null;
        }

        return $data->data instanceof RtStalenessSignalData ? $data->data : null;
    }
}

/**
 * Worker manager standing in for a real one: it opens no connection and starts no agent, and
 * what these cases drive is the one dispatch branch above.
 */
final class WorkerRtStalenessDeliveryTestManager extends WorkerManager
{
    public function __construct()
    {
        parent::__construct(1);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerRtStalenessDeliveryTestAgentManager();
    }
}

/**
 * Agent manager standing in for a real one: these cases start no agent.
 */
final class WorkerRtStalenessDeliveryTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentInterface Never returned; these cases start no agent
     * @throws RuntimeException Always
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new RuntimeException('not used in test');
    }
}

/**
 * The page of these cases: it shows no row of the collection it names, exactly as the screens of
 * the logs section do.
 */
final class WorkerRtStalenessDeliveryTestPage extends AbstractPage
{
    public const string PAGE = 'unit_rt_staleness_page';

    public const array READS_RT = [WorkerRtStalenessDeliveryTest::COLLECTION];
}

/**
 * Browser context standing in for a real one: its topology knows no page at all, which is what
 * makes the declaration the only thing the take-up can have read.
 */
final class WorkerRtStalenessDeliveryTestBrowserContext extends BrowserContext
{
}

/**
 * Project facade standing in for a real one: it registers the one test page and nothing else.
 */
final class WorkerRtStalenessDeliveryTestHilos extends Hilos
{
    public const array PAGES = [
        WorkerRtStalenessDeliveryTestPage::PAGE => WorkerRtStalenessDeliveryTestPage::class,
    ];

    /**
     * @return HilosDbContext Test DB context, for the abstract facade contract alone
     */
    protected static function createDb(): HilosDbContext
    {
        return new WorkerRtStalenessDeliveryTestDbContext();
    }
}

/**
 * No-op DB configuration: these cases touch no database.
 */
final class WorkerRtStalenessDeliveryTestDbContext extends HilosDbContext
{
    public function configure(): void
    {
    }
}

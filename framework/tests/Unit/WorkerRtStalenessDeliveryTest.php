<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Hilos;
use Hilos\Runtime\DTO\RtStalenessSignalData;
use Hilos\Runtime\RtStaleness;
use Hilos\Socket\Worker\DTO\WorkerRtSnapshotMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtStalenessMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;
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
    private const string COLLECTION = 'workerStatuses';

    /** @var float Microtime the rows froze at */
    private const float FROZE_AT = 1000.5;

    /** @var string A second collection the page under test also reads */
    private const string OTHER_COLLECTION = 'jobs';

    /** @var float Microtime the second collection froze at, later than the first */
    private const float FROZE_LATER = 2000.5;

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
        SourceInterestRegistry::readsWhatItMounts();
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page(self::ACCEPT_KEY));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent('unit_rt_staleness:1'));
        Hilos::$sr = null;

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
     * The case a worker respawning during a break would otherwise get wrong: the frame that
     * froze the rows went out before this process existed, and nothing repeats it. So the
     * snapshot that gives the worker the rows gives it their age along with them.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testASnapshotCarriesWhichOfItsRowsAreAlreadyFrozen(): void
    {
        $dto = new WorkerRtSnapshotMessageDTO(
            self::COLLECTION,
            ['row-1' => ['id' => 'row-1'], 'row-2' => ['id' => 'row-2']],
            ['row-1' => self::FROZE_AT],
        );

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerRtSnapshotMessageDTO::class, $parsed);
        $this->assertSame(['row-1' => self::FROZE_AT], $parsed->staleRows);
    }

    /**
     * Off a cluster nothing is ever frozen, so the field is absent from most frames on the wire
     * and its absence has to read as "all of it is current" rather than as a malformed frame.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testASnapshotWithoutTheFieldSaysNothingIsFrozen(): void
    {
        $parsed = WorkerRtSnapshotMessageDTO::fromArray([
            WorkerRtSnapshotMessageDTO::FIELD_COLLECTION_KEY => self::COLLECTION,
            WorkerRtSnapshotMessageDTO::FIELD_ROWS => ['row-1' => ['id' => 'row-1']],
        ]);

        $this->assertSame([], $parsed->staleRows);
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
        $worker = new WorkerRtStalenessDeliveryTestManager();
        $worker->handleDaemonMessage(
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], self::FROZE_AT),
        );

        $worker->handleDaemonMessage(
            new WorkerRtSnapshotMessageDTO(self::COLLECTION, ['row-1' => ['id' => 'row-1']], []),
        );

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

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\PlacementVerdictSink;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Hilos\Tests\Fixtures\SignalDispatcherFixture;
use Closure;
use Generator;
use Hilos\Cluster\AgentSignalMesh;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementId;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRecovery;
use Hilos\Cluster\Placement\PlacementSnapshot;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\DTO\AgentsGoneSignalData;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Daemon\AgentDeliveryOutcome;
use Hilos\Core\Daemon\AgentSignalHold;
use Hilos\Core\Daemon\AgentSignalHolds;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\SignalDispatcher;
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\SignalDispatchPhase;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Exception\InvalidArgumentException as CoreInvalidArgumentException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Router\Destination\AgentAddressedDestination;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\UnknownAgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementExecutor;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementMesh;
use Hilos\Tests\Unit\Cluster\Placement\SnapshotTestLeadership;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionGenerator;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;
use WeakReference;

/**
 * A frame addressed to an agent that is not up yet waits for it in the master (HIL-629).
 *
 * It used to be written to the worker right behind the start, and a start that failed inside the
 * worker took it along - or, for an agent no node was known to host, it was dropped on the spot.
 * Now the master holds it: for the start report of an agent coming up here, or for an address
 * that does not exist yet. It goes to that one agent once the agent is up.
 *
 * The two holds end differently (HIL-1041). A start under way here is going to be reported one way
 * or the other, so that hold lasts as long as the start does. An agent nobody could place waits
 * on a placement verdict, not a clock: the leader names a node, or it answers that it could not.
 * The wait becomes a start-under-way wait the moment the agent turns up starting here.
 *
 * The drain is driven the way {@see DaemonManagerAgentStartRefusedTest} drives it; Reflection
 * reaches the private members because the code-style rule grants tests that exception.
 */
final class DaemonManagerHeldAgentSignalTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-629';

    /** @var string Node a case sends the agent off to, so the placing door has somewhere to forward */
    private const string OTHER_NODE = 'node-b';

    private const string RECOVERY_SOURCE = 'origin-node';

    private const string FIRST_PLACING_LEADER = 'leader';

    private const string NEXT_PLACING_LEADER = 'successor';

    private const int REPLAY_TERM = 7;

    /** @var string Signal name the master facade addresses the agent with */
    private const string MASTER_SIGNAL = 'master_facade_signal';

    /** @var string Signal name a frame forwarded from another node carries */
    private const string FORWARDED_SIGNAL = 'forwarded_signal';

    /** @var string Correlation id the command cases hold their request under */
    private const string CORRELATION_ID = 'corr-1040';

    /**
     * @var float Seconds of waiting a case simulates to stand for a start that is slow but running
     *
     * A multiple of the worker's own budget rather than a number of its own, so it stays well past
     * the ceiling the master used to hold a frame for however that budget moves.
     */
    private const float LONG_START_SECONDS = AgentConstants::START_DEADLINE_SECONDS * 4;

    /** @var ?class-string<Hilos> Facade class bound before a case that declares a listener */
    private ?string $boundAppClass = null;

    protected function tearDown(): void
    {
        if ($this->boundAppClass !== null) {
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
            $this->boundAppClass = null;
        }
        Hilos::$sr = null;
        Hilos::$cluster = null;

        parent::tearDown();
    }

    /**
     * The three places that used to end a lost agent with a log line now tell the agent that
     * declared the fact which agents are gone and why (HIL-1044).
     */
    public function testEveryLossIsToldToTheAgentThatDeclaredIt(): void
    {
        $this->declareGoneListener();
        $manager = new HeldAgentSignalTestManager();

        $manager->reportWorkerLost(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->reportStartFailed(HeldAgentSignalTestRouter::UP_AGENT);
        $manager->reportNotPlaced(HeldAgentSignalTestRouter::FROZEN_AGENT);

        $this->assertSame(
            [
                [[HeldAgentSignalTestRouter::COLD_AGENT], AgentsGoneSignalData::REASON_WORKER_DIED],
                [[HeldAgentSignalTestRouter::UP_AGENT], AgentsGoneSignalData::REASON_START_FAILED],
                [[HeldAgentSignalTestRouter::FROZEN_AGENT], AgentsGoneSignalData::REASON_NOT_PLACED],
            ],
            $this->goneFrames(),
        );
    }

    /**
     * Nobody declares the fact, so nobody is waiting on it: the master sends nothing rather than
     * a frame with no route.
     */
    public function testALossNobodyDeclaredAFactForSendsNothing(): void
    {
        $manager = new HeldAgentSignalTestManager();

        $manager->reportWorkerLost(HeldAgentSignalTestRouter::COLD_AGENT);

        $this->assertSame([], $this->goneFrames());
    }

    /**
     * @param string $report Actual report entry on the fixture
     * @param string $goneReason Expected existing loss reason
     */
    #[DataProvider('cohortNegativeReports')]
    public function testALargeNegativeReportKeepsItsOriginalCohortAndCancellationAcrossPortions(string $report, string $goneReason): void
    {
        $this->declareGoneListener();
        $manager = new HeldAgentSignalTestManager();
        for ($index = 0; $index < 130; $index++) {
            $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, new SignalDTO(
                new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::COMMAND_REQUEST),
                new SignalName(HeldAgentSignalTestRouter::COLD_COMMAND),
                new CommandRequestDTO('cohort-' . $index, HeldAgentSignalTestRouter::COLD_COMMAND),
            ));
        }
        self::assertCount(130, $manager->heldFrames());
        $manager->$report(HeldAgentSignalTestRouter::COLD_AGENT);
        self::assertGreaterThanOrEqual(66, count($manager->heldFrames()), 'A report cannot settle its whole growing cohort');
        self::assertLessThanOrEqual(64, Hilos::$sr->getQueuedSignalCount());
        self::assertSame([], $manager->lossHookQueuedCounts);
        $manager->onCommandAbandoned('cohort-100');
        $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, new SignalDTO(
            new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(HeldAgentSignalTestRouter::COLD_COMMAND),
            new CommandRequestDTO('cohort-later', HeldAgentSignalTestRouter::COLD_COMMAND),
        ));

        $state = SignalDispatcherFixture::owner($manager)->retainedState();
        $dispatcher = new ReflectionMethod(DaemonManager::class, 'signalDispatcher')->invoke($manager);
        $advance = new ReflectionMethod(SignalDispatcher::class, 'advanceSignalEffect');
        for ($step = 0; $step < 1000 && $state->effects !== []; $step++) {
            $advance->invoke($dispatcher, key($state->effects));
        }
        self::assertSame([], $state->effects);
        self::assertCount(1, $manager->heldFrames(), 'Later admissions were outside the report cohort');
        self::assertSame('cohort-later', $manager->heldFrames()[0]->signal->data->correlationId);
        $replies = [];
        $gone = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_AGENTS_GONE) {
                self::assertCount(129, $replies, 'All owed refusals precede the gone announcement');
                $gone = $signal->data->data;
            } else {
                self::assertNull($gone, 'No refusal follows the gone announcement');
                $replies[] = $signal->data->correlationId;
            }
        }
        $expected = [];
        for ($index = 0; $index < 130; $index++) {
            if ($index !== 100) {
                $expected[] = 'cohort-' . $index;
            }
        }
        self::assertSame($expected, $replies);
        self::assertSame($goneReason, $gone?->reason);
        self::assertSame($report === 'reportWorkerLost' ? [130] : [], $manager->lossHookQueuedCounts);
    }

    /** @return iterable<string, array{string, string}> Every negative report ending an original cohort */
    public static function cohortNegativeReports(): iterable
    {
        yield 'placement refusal' => ['reportNotPlaced', AgentsGoneSignalData::REASON_NOT_PLACED];
        yield 'failed start' => ['reportStartFailed', AgentsGoneSignalData::REASON_START_FAILED];
        yield 'worker loss' => ['reportWorkerLost', AgentsGoneSignalData::REASON_WORKER_DIED];
    }

    public function testAFailedRefusalDoesNotRetryAnAnswerThatWasAlreadyQueued(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::COLD_PAGE);
        $manager->drainQueue();
        $failure = new RuntimeException('refusal observer failed after enqueue');
        $manager->refusalFailure = $failure;
        try {
            $manager->reportNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT);
            self::fail('The original refusal failure must propagate');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertCount(1, $manager->pageErrorFrames());
        self::assertSame([], $manager->heldFrames(), 'An ambiguous refusal attempt cannot be retried by another report');
        $manager->refusalFailure = null;
        $manager->reportNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT);
        self::assertCount(1, $manager->pageErrorFrames());
        $state = SignalDispatcherFixture::owner($manager)->retainedState();
        self::assertSame([], $state->effects);
    }

    /**
     * A frame about its own death would start the failing listener again, and again on every
     * failure after - so the listener is never told about itself.
     */
    public function testTheListenerIsNeverToldAboutItself(): void
    {
        $this->declareGoneListener();
        $manager = new HeldAgentSignalTestManager();

        $manager->reportStartFailed(AgentsGoneTestListener::TYPE);
        $manager->reportWorkerLost(AgentsGoneTestListener::TYPE);

        $this->assertSame([], $this->goneFrames());
    }

    /**
     * @param string $report Actual report entry
     * @param string $reason Existing loss reason
     */
    #[DataProvider('cohortNegativeReports')]
    public function testEachReportAnnouncesBothIndependentSingletonChannels(string $report, string $reason): void
    {
        $this->declareGoneListeners(AgentsGoneBothTestHilos::class);
        $manager = new HeldAgentSignalTestManager();
        $manager->$report(HeldAgentSignalTestRouter::COLD_AGENT);
        $frames = $this->namedGoneFrames();
        self::assertSame([
            HilosSignalConstants::HILOS_AGENTS_GONE,
            HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE,
        ], array_keys($frames));
        foreach ($frames as $frame) {
            self::assertSame([HeldAgentSignalTestRouter::COLD_AGENT], $frame->data->data->agentIds);
            self::assertSame($reason, $frame->data->data->reason);
            self::assertSame(SignalSource::DAEMON, $frame->signalSource->getSource());
        }
        $this->deliverGoneFrames($manager, $frames);
        self::assertSame([
            HilosSignalConstants::HILOS_AGENTS_GONE . '@' . AgentsGoneTestListener::TYPE,
            HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE . '@' . OAuthAgentsGoneTestListener::TYPE,
        ], $manager->workerServer->deliveries);
    }

    /**
     * @param class-string<Hilos> $facade Declared scalar receiving routes
     * @param list<string> $names Expected channel names
     */
    #[DataProvider('goneChannelDeclarations')]
    public function testMissingLossRoutesAreSkippedIndependently(string $facade, array $names): void
    {
        $this->declareGoneListeners($facade);
        $manager = new HeldAgentSignalTestManager();
        $manager->reportWorkerLost(HeldAgentSignalTestRouter::COLD_AGENT);
        self::assertSame($names, array_keys($this->namedGoneFrames()));
        $manager->reportAgentsLostWithWorker(3, false, []);
        self::assertSame([], $this->namedGoneFrames());
    }

    /** @return iterable<string, array{class-string<Hilos>, list<string>}> Independent activation */
    public static function goneChannelDeclarations(): iterable
    {
        yield 'sessions only' => [AgentsGoneTestHilos::class, [HilosSignalConstants::HILOS_AGENTS_GONE]];
        yield 'OAuth only' => [OAuthAgentsGoneTestHilos::class, [HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE]];
        yield 'neither' => [NoAgentsGoneTestHilos::class, []];
    }

    /**
     * @param string $report Report entry; worker loss also accepts both recipients together
     * @param list<string> $ids Original gone cohort
     * @param list<string> $names Channels whose own recipient survived
     */
    #[DataProvider('goneRecipientLosses')]
    public function testOnlyTheLostRecipientChannelIsExcluded(string $report, array $ids, array $names): void
    {
        $this->declareGoneListeners(AgentsGoneBothTestHilos::class);
        $manager = new HeldAgentSignalTestManager();
        if ($report === 'reportWorkerLost') {
            $manager->reportAgentsLostWithWorker(3, false, $ids);
        } else {
            $manager->$report($ids[0]);
        }
        $frames = $this->namedGoneFrames();
        self::assertSame($names, array_keys($frames));
        foreach ($frames as $frame) {
            self::assertSame($ids, $frame->data->data->agentIds);
        }
        $this->deliverGoneFrames($manager, $frames);
        foreach ($ids as $id) {
            self::assertNotContains($id, $manager->workerServer->startedAgentTypes, 'A failing recipient must not restart itself');
        }
        self::assertCount(count($names), $manager->workerServer->deliveries);
    }

    /** @return iterable<string, array{string, list<string>, list<string>}> Loss of each recipient and both */
    public static function goneRecipientLosses(): iterable
    {
        foreach (['reportWorkerLost', 'reportStartFailed', 'reportNotPlaced'] as $report) {
            yield $report . ' sessions' => [$report, [AgentsGoneTestListener::TYPE], [HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE]];
            yield $report . ' OAuth' => [$report, [OAuthAgentsGoneTestListener::TYPE], [HilosSignalConstants::HILOS_AGENTS_GONE]];
        }
        yield 'both in one worker cohort' => ['reportWorkerLost', [AgentsGoneTestListener::TYPE, OAuthAgentsGoneTestListener::TYPE], []];
    }

    /**
     * The held cohort and cancellation exist inside one master, in a window shorter than a stand tick.
     *
     * @param string $report Actual report entry
     * @param string $reason Existing loss reason
     */
    #[DataProvider('cohortNegativeReports')]
    public function testBothChannelsFollowTheOriginalCohortRefusalsAcrossYields(string $report, string $reason): void
    {
        $this->declareGoneListeners(AgentsGoneBothTestHilos::class);
        $manager = new HeldAgentSignalTestManager();
        for ($index = 0; $index < 130; $index++) {
            $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, new SignalDTO(
                new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::COMMAND_REQUEST),
                new SignalName(HeldAgentSignalTestRouter::COLD_COMMAND),
                new CommandRequestDTO('two-channels-' . $index, HeldAgentSignalTestRouter::COLD_COMMAND),
            ));
        }
        $ids = [HeldAgentSignalTestRouter::COLD_AGENT];
        if ($report === 'reportWorkerLost') {
            for ($index = 0; $index < 140; $index++) {
                $ids[] = 'gone_person:' . $index;
            }
            $manager->reportAgentsLostWithWorker(3, false, $ids);
        } else {
            $manager->$report(HeldAgentSignalTestRouter::COLD_AGENT);
        }
        self::assertGreaterThanOrEqual(66, count($manager->heldFrames()));
        self::assertLessThanOrEqual(64, Hilos::$sr->getQueuedSignalCount());
        self::assertSame([], $manager->lossHookQueuedCounts);
        $manager->onCommandAbandoned('two-channels-100');
        $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, new SignalDTO(
            new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(HeldAgentSignalTestRouter::COLD_COMMAND),
            new CommandRequestDTO('two-channels-later', HeldAgentSignalTestRouter::COLD_COMMAND),
        ));
        $state = SignalDispatcherFixture::owner($manager)->retainedState();
        $dispatcher = new ReflectionMethod(DaemonManager::class, 'signalDispatcher')->invoke($manager);
        $advance = new ReflectionMethod(SignalDispatcher::class, 'advanceSignalEffect');
        for ($step = 0; $step < 1000 && $state->effects !== []; $step++) {
            $advance->invoke($dispatcher, key($state->effects));
        }
        self::assertSame([], $state->effects);
        self::assertCount(1, $manager->heldFrames());
        self::assertSame('two-channels-later', $manager->heldFrames()[0]->signal->data->correlationId);
        $replies = [];
        $gone = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if (in_array($name, [HilosSignalConstants::HILOS_AGENTS_GONE, HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE], true)) {
                self::assertCount(129, $replies, 'Every owed refusal must precede both announcements');
                self::assertArrayNotHasKey($name, $gone);
                $gone[$name] = $signal->data->data;
            } else {
                self::assertSame([], $gone, 'No refusal may follow either announcement');
                $replies[] = $signal->data->correlationId;
            }
        }
        $expected = [];
        for ($index = 0; $index < 130; $index++) {
            if ($index !== 100) {
                $expected[] = 'two-channels-' . $index;
            }
        }
        self::assertSame($expected, $replies);
        self::assertSame([
            HilosSignalConstants::HILOS_AGENTS_GONE, HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE,
        ], array_keys($gone));
        foreach ($gone as $frame) {
            self::assertSame($ids, $frame->agentIds);
            self::assertSame($reason, $frame->reason);
        }
        self::assertSame($report === 'reportWorkerLost' ? [131] : [], $manager->lossHookQueuedCounts);
        $manager->reportNotPlaced(HeldAgentSignalTestRouter::UP_AGENT);
        $laterGone = $this->namedGoneFrames();
        self::assertSame(array_keys($gone), array_keys($laterGone));
        foreach ($laterGone as $frame) {
            self::assertSame([HeldAgentSignalTestRouter::UP_AGENT], $frame->data->data->agentIds);
            self::assertSame(AgentsGoneSignalData::REASON_NOT_PLACED, $frame->data->data->reason);
        }
        self::assertSame('two-channels-later', $manager->heldFrames()[0]->signal->data->correlationId);
    }

    public function testAFrameForAnAgentThatHasNotReportedItsStartIsHeld(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queuePush(HeldAgentSignalTestRouter::COLD_PUSH);

        $manager->drainQueue();

        $this->assertSame([HeldAgentSignalTestRouter::COLD_AGENT], $manager->workerServer->startedAgentTypes);
        $this->assertSame([], $manager->workerServer->deliveries);
    }

    /**
     * The report lets the frame go at the head of the next drain, so a frame queued behind it for
     * the same agent still arrives second.
     */
    public function testTheHeldFrameIsDeliveredOnceTheStartIsReportedAndAheadOfWhatFollowedIt(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queuePush(HeldAgentSignalTestRouter::COLD_PUSH);
        $manager->drainQueue();

        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $this->queuePush(HeldAgentSignalTestRouter::COLD_FOLLOW_UP);
        $manager->drainQueue();

        $this->assertSame(
            [
                HeldAgentSignalTestRouter::COLD_PUSH . '@' . HeldAgentSignalTestRouter::COLD_AGENT,
                HeldAgentSignalTestRouter::COLD_FOLLOW_UP . '@' . HeldAgentSignalTestRouter::COLD_AGENT,
            ],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * The frame was held on one destination of two; the other was reached by the walk already and
     * must not be reached again when the held one is let go.
     */
    public function testLettingTheFrameGoReachesOnlyTheAgentItWaitedFor(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queuePush(HeldAgentSignalTestRouter::SHARED_PUSH);
        $manager->drainQueue();

        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();

        $this->assertSame(
            [
                HeldAgentSignalTestRouter::SHARED_PUSH . '@' . HeldAgentSignalTestRouter::UP_AGENT,
                HeldAgentSignalTestRouter::SHARED_PUSH . '@' . HeldAgentSignalTestRouter::COLD_AGENT,
            ],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * A page whose agent is starting here waits as long as the start runs and is answered by the
     * report, never by a clock: the hold is not waiting on a placement verdict, so simulated
     * waiting far past the ceiling the master used to give itself changes nothing about it
     * (HIL-1040).
     */
    public function testAFrameForAStartUnderWayOutlastsTheOldCeilingAndGoesOutOnTheReport(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::COLD_PAGE);
        $manager->drainQueue();

        $held = $manager->heldFrames();
        $this->assertCount(1, $held);
        $this->assertFalse($held[0]->awaitingPlacement);

        $manager->ageHeldFrames(self::LONG_START_SECONDS);
        $manager->drainQueue();

        $this->assertSame([], $manager->pageErrorFrames());
        $this->assertCount(1, $manager->heldFrames());

        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();

        $this->assertSame([], $manager->pageErrorFrames());
        $this->assertSame(
            [HeldAgentSignalTestRouter::COLD_PAGE . '@' . HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * The one hold that waits on a placement verdict stops doing so the moment its agent turns
     * up starting here: from then on there IS a report coming, and the frame waits on it like
     * any other. Otherwise a missing address would keep asking for a placement while a start
     * is already running here.
     */
    public function testAnAddresslessHoldStopsWaitingOnPlacementOnceTheStartBeginsHere(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);
        $manager->drainQueue();
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacement);

        $manager->workerServer->ensureAgentUp(HeldAgentSignalTestRouter::COLD_AGENT, null);
        $manager->drainQueue();
        $this->assertFalse($manager->heldFrames()[0]->awaitingPlacement);

        $manager->ageHeldFrames(self::LONG_START_SECONDS);
        $manager->drainQueue();
        $this->assertSame([], $manager->pageErrorFrames());

        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();

        $this->assertSame(
            [HeldAgentSignalTestRouter::UNPLACED_PAGE . '@' . HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * The report is the answer the deadline stands in for: the page is told at once, in the words
     * of a start refused on this node, and the master forgets the agent, so the next frame starts
     * it again instead of waiting on a record nothing will ever report on.
     */
    public function testAReportedStartFailureAnswersAtOnceAndTheNextFrameStartsTheAgentAgain(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::COLD_PAGE);
        $manager->drainQueue();

        $manager->reportStartFailed(HeldAgentSignalTestRouter::COLD_AGENT);

        $frames = $manager->pageErrorFrames();
        $this->assertCount(1, $frames);
        $error = $frames[0]->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame('agent_unavailable', $error->errorCode);
        $this->assertSame([], $manager->heldFrames());
        $this->assertFalse($manager->holdsRecordOf(HeldAgentSignalTestRouter::COLD_AGENT));

        $manager->drainQueue();
        $this->queuePush(HeldAgentSignalTestRouter::COLD_PUSH);
        $manager->drainQueue();

        $this->assertSame(
            [HeldAgentSignalTestRouter::COLD_AGENT, HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->startedAgentTypes,
        );
        $this->assertCount(1, $manager->heldFrames());
    }

    public function testAClosedConnectionTakesItsHeldSubscriptionWithIt(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::COLD_PAGE);
        $manager->drainQueue();

        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName(SignalTypeConstants::CONNECTION_CLOSE),
            new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
        );
        $manager->drainQueue();
        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();

        $this->assertSame([], $manager->workerServer->deliveries);
    }

    public function testAnAgentAlreadyUpIsDeliveredToStraightAway(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queuePush(HeldAgentSignalTestRouter::UP_PUSH);

        $manager->drainQueue();

        $this->assertSame([], $manager->workerServer->startedAgentTypes);
        $this->assertSame(
            [HeldAgentSignalTestRouter::UP_PUSH . '@' . HeldAgentSignalTestRouter::UP_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * A start the freeze refuses leaves no start under way, so there is nothing
     * to wait for: the frame goes on to the delivery that answers it the way it always has.
     */
    public function testAFrameWhoseStartWasRefusedQuietlyIsNotHeld(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queuePush(HeldAgentSignalTestRouter::FROZEN_PUSH);

        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame(
            [HeldAgentSignalTestRouter::FROZEN_PUSH . '@' . HeldAgentSignalTestRouter::FROZEN_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    public function testReplayHoldsWaitForTheExactLocalWorkerReportAndKeepTheirOrderAfterFailure(): void
    {
        $manager = new HeldAgentSignalTestManager();
        [$placement] = $this->recoveringPlacement($manager);
        $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
        $original = $placement->localPlacementId($agent);
        $manager->workerServer->seatAgentUnderWay($agent);
        $manager->sendToAgent($agent, null, self::MASTER_SIGNAL, new SignalData());
        $manager->drainQueue();
        $originalHold = $manager->agentSignalHolds()->getWatermark();

        $recovery = $manager->workerServer->reportPlacementStartFailure($agent, $original, 'replay failed');
        self::assertSame($original, $recovery?->origin);
        $manager->reportStartFailed($agent, $recovery);
        $ask = $this->askForVerdict($placement, $agent);
        $placement->onPlacementVerdict(self::FIRST_PLACING_LEADER, PeerPlacementVerdictDTO::notPlaced(
            $agent, null, PlacementState::Failed, 'replay failed',
        )->forRequest($ask));
        // The old compact address still points here. The second frame joins the same FIFO.
        $manager->deliverSignalToAgent($agent, null, $this->forwardedSignal(), placementRecoveryRelay: true);
        $manager->drainQueue();
        self::assertSame($originalHold + 1, $manager->agentSignalHolds()->getWatermark());
        self::assertSame($original, $manager->agentSignalHolds()->get($originalHold)->parked->recovery->origin);
        self::assertCount(2, $manager->heldFrames());
        self::assertSame([], $manager->workerServer->deliveries);

        $retry = new PlacementId(self::FIRST_PLACING_LEADER, self::REPLAY_TERM, 2);
        $placement->onPlaceAgent(self::FIRST_PLACING_LEADER, new PeerPlaceAgentDTO($agent, null, $retry));
        $manager->workerServer->seatAgentUnderWay($agent);
        $manager->reportStarted($agent);
        $manager->workerServer->reportPlacementWorkerStarted($agent, $original);
        $manager->drainQueue();
        self::assertCount(2, $manager->heldFrames());
        self::assertSame([], $manager->workerServer->deliveries);

        $manager->workerServer->reportPlacementWorkerStarted($agent, $retry);
        $manager->drainQueue();
        $manager->drainQueue();
        self::assertSame([self::MASTER_SIGNAL . '@' . $agent, self::FORWARDED_SIGNAL . '@' . $agent], $manager->workerServer->deliveries);
        self::assertSame([], $manager->heldFrames());
    }

    /** @return iterable<string, array{bool}> Direct facade entry or a peer effect in the start-report drain */
    public static function lateReplayEntries(): iterable
    {
        yield 'direct' => [false];
        yield 'peer effect' => [true];
    }

    /** @param bool $peer Whether the younger frame enters the same batch as the worker report */
    #[DataProvider('lateReplayEntries')]
    public function testAFrameEnteringAfterWorkerProofQueuesBehindTheLiveRecoveryHold(bool $peer): void
    {
        $manager = new HeldAgentSignalTestManager();
        [$placement] = $this->recoveringPlacement($manager);
        $dispatcher = SignalDispatcherFixture::owner($manager);
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($manager);
        try {
            $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
            $manager->workerServer->seatAgentUnderWay($agent);
            $manager->sendToAgent($agent, null, HeldAgentSignalTestRouter::ORDERED_PUSH, new SignalData());
            $manager->drainQueue();
            self::assertCount(1, $manager->heldFrames());

            $manager->workerServer->reportPlacementWorkerStarted($agent, $placement->localPlacementId($agent));
            $manager->reportStarted($agent);
            self::assertNull($placement->placementRecovery($agent));
            self::assertSame([], $manager->workerServer->deliveries);
            if ($peer) {
                $manager->deliverSignalToAgent($agent, null, $this->forwardedSignal());
                $signalName = self::FORWARDED_SIGNAL;
            } else {
                $manager->sendToAgent($agent, null, self::MASTER_SIGNAL, new SignalData());
                $signalName = self::MASTER_SIGNAL;
            }
            $manager->drainQueue();
            $manager->drainQueue();
            self::assertSame([HeldAgentSignalTestRouter::ORDERED_PUSH . '@' . $agent, $signalName . '@' . $agent],
                $manager->workerServer->deliveries);
            self::assertSame([], $manager->heldFrames());
        } finally {
            $dispatcher->detachEventLoop();
            $loop->close();
        }
    }

    #[DataProvider('recoveryProjectionChanges')]
    public function testOnlyTheAcceptedNewWorkerProofReleasesTheAddressedPeerQueue(bool $sameProjectedAddress): void
    {
        $manager = new HeldAgentSignalTestManager();
        [$placement, $authority] = $this->recoveringPlacement($manager);
        $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
        $original = $placement->localPlacementId($agent);
        [$peer, $link, $reader] = $this->peerRecoveryRoute($manager);
        try {
            $manager->sendToAgent($agent, null, self::MASTER_SIGNAL, new SignalData());
            $signal = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName(self::FORWARDED_SIGNAL), new SignalData(['kept' => true]), ['correlation' => 'replay-case']);
            $peer->onSignalReceived($link, new PeerSignalDTO(self::OTHER_NODE, self::RECOVERY_SOURCE, $agent, null, $signal));
            $peer->onSignalReceived($link, new PeerSignalDTO(
                self::OTHER_NODE, self::RECOVERY_SOURCE, $agent, null, $this->forwardedSignal(), placementRecoveryRelay: true,
            ));
            $peer->onSignalReceived($link, new PeerSignalDTO(self::OTHER_NODE, 'wrong-target', $agent, null, $signal));
            $manager->drainQueue();
            self::assertCount(3, $manager->heldFrames());

            $recovery = $manager->workerServer->reportPlacementStartFailure($agent, $original, 'replay failed');
            $manager->reportStartFailed($agent, $recovery);
            $ask = $this->askForVerdict($placement, $agent);
            $placement->onPlacementVerdict(self::FIRST_PLACING_LEADER, PeerPlacementVerdictDTO::notPlaced(
                $agent, null, PlacementState::Failed, 'replay failed',
            )->forRequest($ask));
            $this->acceptRecoveryView($placement, self::FIRST_PLACING_LEADER, self::REPLAY_TERM, 2, [], [
                new PlacementRecord($agent, null, self::RECOVERY_SOURCE, PlacementState::Failed, 'replay failed', placementId: $original),
            ]);
            $manager->drainQueue();
            self::assertFalse($link->hasPendingWrite());
            self::assertCount(3, $manager->heldFrames());

            $term = self::REPLAY_TERM + 1;
            $authority->leader = self::NEXT_PLACING_LEADER;
            $authority->currentTerm = $term;
            $projection = $sameProjectedAddress ? self::OTHER_NODE : self::RECOVERY_SOURCE;
            $stillOld = new PlacementRecord($agent, null, self::OTHER_NODE, PlacementState::Started,
                placementId: $original, workerStarted: true);
            $this->acceptRecoveryView($placement, self::NEXT_PLACING_LEADER, $term, 1, [$projection => [new PeerPlacedAgentEntry($agent, null)]],
                [$stillOld]);
            $manager->drainQueue();
            self::assertFalse($link->hasPendingWrite(), 'A new term adopting the old order proves no new start');

            $retry = new PlacementId(self::NEXT_PLACING_LEADER, $term, 1);
            $records = [
                new PlacementRecord($agent, null, self::OTHER_NODE, PlacementState::Placing, placementId: $retry),
                new PlacementRecord('another_agent', null, self::OTHER_NODE, PlacementState::Started,
                    placementId: new PlacementId(self::NEXT_PLACING_LEADER, $term, 2), workerStarted: true),
            ];
            $this->acceptRecoveryView($placement, self::NEXT_PLACING_LEADER, $term, 2,
                [$projection => [new PeerPlacedAgentEntry($agent, null)]], $records);
            $records[0] = $records[0]->withState(PlacementState::Started);
            $this->acceptRecoveryView($placement, self::NEXT_PLACING_LEADER, $term, 3,
                [$projection => [new PeerPlacedAgentEntry($agent, null)]], $records);
            $manager->drainQueue();
            self::assertCount(3, $manager->heldFrames());
            self::assertFalse($link->hasPendingWrite(), 'Neither another agent nor an early Started releases this FIFO');

            $records[0] = new PlacementRecord($agent, null, self::OTHER_NODE, PlacementState::Started,
                placementId: $retry, workerStarted: true);
            $this->acceptRecoveryView($placement, self::NEXT_PLACING_LEADER, $term, 2,
                [self::OTHER_NODE => [new PeerPlacedAgentEntry($agent, null)]], $records);
            $manager->drainQueue();
            self::assertFalse($link->hasPendingWrite(), 'A stale complete snapshot supplies no proof');
            $this->acceptRecoveryView($placement, self::NEXT_PLACING_LEADER, $term, 4,
                [self::OTHER_NODE => [new PeerPlacedAgentEntry($agent, null)]], $records);
            $manager->drainQueue();
            self::assertSame([], $manager->heldFrames());
            self::assertSame([], $manager->workerServer->deliveries);
            self::assertTrue($link->hasPendingWrite());

            $link->write();
            $bytes = socket_read($reader, 8192);
            $frames = array_map(PeerDTO::fromWire(...), explode("\n", trim($bytes)));
            self::assertCount(2, $frames);
            self::assertSame([self::MASTER_SIGNAL, self::FORWARDED_SIGNAL],
                array_map(static fn(PeerSignalDTO $frame): string => $frame->signal->signalName->getName(), $frames));
            self::assertFalse($frames[0]->placementRecoveryRelay);
            self::assertTrue($frames[1]->placementRecoveryRelay);
            self::assertSame(self::OTHER_NODE, $frames[1]->targetNodeId);
            self::assertSame($agent, $frames[1]->agentType);
            self::assertNull($frames[1]->agentIndex);
            self::assertSame($signal->toArray(), $frames[1]->signal->toArray());

            $this->acceptRecoveryView($placement, self::NEXT_PLACING_LEADER, $term, 5,
                [self::OTHER_NODE => [new PeerPlacedAgentEntry($agent, null)]], $records);
            [$replacement, $replacementReader] = $this->attachRecoveryPeer($peer);
            try {
                $ask = $this->askForVerdict($placement, $agent);
                $placement->onPlacementVerdict(self::NEXT_PLACING_LEADER,
                    PeerPlacementVerdictDTO::placed($agent, null, self::OTHER_NODE)->forRequest($ask));
                $manager->drainQueue();
                self::assertFalse($replacement->hasPendingWrite(), 'A replacement link cannot enqueue a settled hold again');
                self::assertFalse($link->hasPendingWrite(), 'Subsequent wakeups cannot enqueue a frame a second time');
            } finally {
                $replacement->close();
                socket_close($replacementReader);
            }
        } finally {
            $link->close();
            socket_close($reader);
        }
    }

    public static function recoveryProjectionChanges(): iterable
    {
        yield 'compact address changes' => [false];
        yield 'compact address already agrees' => [true];
    }

    public function testSuspendedRecoveryCannotOvertakeItsPredecessorAfterTwoAddressChanges(): void
    {
        $manager = new HeldAgentSignalTestManager();
        [$placement] = $this->recoveringPlacement($manager);
        $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
        [$peer, $link, $reader] = $this->peerRecoveryRoute($manager);
        try {
            $manager->sendToAgent($agent, null, self::MASTER_SIGNAL, new SignalData());
            $manager->deliverSignalToAgent($agent, null, $this->forwardedSignal());
            $manager->drainQueue();
            $holds = $manager->agentSignalHolds();
            [$older, $younger] = iterator_to_array($holds->agentCandidates($agent), false);
            $origin = $holds->get($older)->parked->recovery;
            $dispatcher = SignalDispatcherFixture::owner($manager);
            $state = $dispatcher->retainedState();
            $advance = new ReflectionMethod(SignalDispatcher::class, 'advanceReleasedSignal');
            $firstNode = 'first-host';
            $secondNode = 'second-host';
            $this->acceptRecoveryAddress($placement, $agent, $firstNode, 2);
            $holds->release($older);
            $holds->release($younger);
            $this->advanceToRecoveryAddressYield($advance, $dispatcher, $manager, $peer);
            self::assertSame($older, $state->released->heldId);
            $this->acceptRecoveryAddress($placement, $agent, $secondNode, 3);
            $advance->invoke($dispatcher, $manager->workerServer, $peer);
            self::assertSame($origin, $holds->get($older)->parked?->recovery);

            $this->advanceToRecoveryAddressYield($advance, $dispatcher, $manager, $peer);
            self::assertSame($older, $state->released->heldId);
            self::assertSame([$younger], $state->suspendedReleases);
            $this->acceptRecoveryAddress($placement, $agent, self::OTHER_NODE, 4);
            $advance->invoke($dispatcher, $manager->workerServer, $peer);
            self::assertSame($origin, $holds->get($older)->parked?->recovery);
            $holds->release($older);

            $manager->drainQueue();
            $this->acceptRecoveryAddress($placement, $agent, self::OTHER_NODE, 5);
            $manager->drainQueue();
            $link->write();
            $wire = new PlainSocketTransport($reader)->read(65536);
            self::assertIsString($wire);
            $frames = array_map(PeerDTO::fromWire(...), explode("\n", trim($wire)));
            self::assertSame([self::MASTER_SIGNAL, self::FORWARDED_SIGNAL], array_map(
                static fn (PeerSignalDTO $frame): string => $frame->signal->signalName->getName(), $frames,
            ));
            self::assertSame([false, true], array_column($frames, 'placementRecoveryRelay'));
            self::assertSame([self::OTHER_NODE, self::OTHER_NODE], array_column($frames, 'targetNodeId'));
            self::assertSame($younger, $holds->getWatermark());
            self::assertSame([], iterator_to_array($holds->agentCandidates($agent), false));
        } finally {
            $link->close();
            socket_close($reader);
        }
    }

    public function testAReleasedRelayReparksWithTheSameBasisAndCannotRelayAgain(): void
    {
        $manager = new HeldAgentSignalTestManager();
        [$placement, $authority] = $this->recoveringPlacement($manager);
        $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
        $manager->deliverSignalToAgent($agent, null, $this->forwardedSignal(), placementRecoveryRelay: true);
        $manager->drainQueue();
        $holdId = $manager->agentSignalHolds()->getWatermark();
        $original = $manager->heldFrames()[0]->recovery;
        $manager->agentSignalHolds()->release($holdId);
        $cursor = $manager->beginReleasedCursor();
        $manager->advanceReleasedCursor($cursor, 1);
        $authority->leader = self::NEXT_PLACING_LEADER;
        $authority->currentTerm = self::REPLAY_TERM + 1;
        $manager->advanceReleasedCursor($cursor, 25);

        self::assertSame($holdId, $manager->agentSignalHolds()->getWatermark());
        self::assertSame($original, $manager->heldFrames()[0]->recovery);
        self::assertTrue($manager->heldFrames()[0]->placementRecoveryRelay);
        [, $link, $reader] = $this->peerRecoveryRoute($manager);
        try {
            $this->acceptRecoveryView($placement, self::NEXT_PLACING_LEADER, $authority->currentTerm, 1,
                [self::OTHER_NODE => [new PeerPlacedAgentEntry($agent, null)]], [
                    new PlacementRecord($agent, null, self::OTHER_NODE, PlacementState::Started,
                        placementId: new PlacementId(self::NEXT_PLACING_LEADER, $authority->currentTerm, 1), workerStarted: true),
                ]);
            $manager->drainQueue();
            self::assertSame([], $manager->heldFrames());
            self::assertFalse($link->hasPendingWrite());
        } finally {
            $link->close();
            socket_close($reader);
        }
    }

    public function testTerminalPlacementRefusalStillSettlesTheOriginalRecoveryHold(): void
    {
        $manager = new HeldAgentSignalTestManager();
        [$placement] = $this->recoveringPlacement($manager);
        $this->queueSubscribe(HeldAgentSignalTestRouter::ORDERED_PAGE);
        $manager->drainQueue();
        self::assertCount(1, $manager->heldFrames());
        $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
        $ask = $this->askForVerdict($placement, $agent);
        $placement->onPlacementVerdict(self::FIRST_PLACING_LEADER, PeerPlacementVerdictDTO::notPlaced(
            $agent, null, PlacementState::Refused, 'claim refused',
        )->forRequest($ask));
        $manager->drainQueue();
        self::assertCount(1, $manager->heldFrames());
        self::assertSame([], $manager->pageErrorFrames());
        $this->acceptRecoveryView($placement, self::FIRST_PLACING_LEADER, self::REPLAY_TERM, 2, [], [
            new PlacementRecord($agent, null, self::RECOVERY_SOURCE, PlacementState::Refused,
                placementId: $placement->localPlacementId($agent)),
        ]);
        $manager->drainQueue();
        self::assertSame([], $manager->heldFrames());
        self::assertCount(1, $manager->pageErrorFrames());
    }

    public function testOldVerdictAndTerminalSnapshotCannotSettleTheNewHoldAfterItsHostedOrderFailed(): void
    {
        $manager = new HeldAgentSignalTestManager();
        [$placement, $authority] = $this->recoveringPlacement($manager);
        $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
        $old = $placement->localPlacementId($agent);
        $oldAsk = $this->askForVerdict($placement, $agent);
        $authority->leader = self::NEXT_PLACING_LEADER;
        $term = ++$authority->currentTerm;
        $current = new PlacementId(self::NEXT_PLACING_LEADER, $term, 1);
        $placement->onPlaceAgent(self::NEXT_PLACING_LEADER, new PeerPlaceAgentDTO($agent, null, $current));
        $placement->requirePlacement($agent, null);
        $manager->sendToAgent($agent, null, self::MASTER_SIGNAL, new SignalData());
        $manager->drainQueue();
        self::assertCount(1, $manager->heldFrames());
        $id = $manager->agentSignalHolds()->getWatermark();
        $late = PeerPlacementVerdictDTO::notPlaced($agent, null, PlacementState::Refused, 'previous order refused');
        $placement->onPlacementVerdict(self::FIRST_PLACING_LEADER, $late->forRequest($oldAsk));
        $this->acceptRecoveryView($placement, self::NEXT_PLACING_LEADER, $term, 1, [], [
            new PlacementRecord($agent, null, null, PlacementState::Stopped, placementId: $old),
        ]);
        $recovery = $manager->workerServer->reportPlacementStartFailure($agent, $current, 'worker did not start');
        self::assertNotNull($recovery);
        self::assertNull($placement->localPlacementId($agent));
        $manager->reportStartFailed($agent);
        $manager->drainQueue();
        self::assertSame($id, $manager->agentSignalHolds()->getWatermark());
        self::assertSame($recovery, $manager->heldFrames()[0]->recovery);
        self::assertFalse($placement->isPlacementRecoveryCanceled($agent, $recovery));

        $this->acceptRecoveryView($placement, self::NEXT_PLACING_LEADER, $term, 2, [], [
            new PlacementRecord($agent, null, null, PlacementState::Stopped, placementId: $current),
        ]);
        $manager->drainQueue();
        self::assertSame([], $manager->heldFrames());
    }

    public function testStopWhileRefrozenKeepsTheExactPendingSubscriptionInItsOriginalHold(): void
    {
        $manager = new HeldAgentSignalTestManager();
        [$placement] = $this->recoveringPlacement($manager);
        $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
        $order = $placement->localPlacementId($agent);
        $page = HeldAgentSignalTestRouter::ORDERED_PAGE;
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($page), new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $page, deliveryId: 'replay-subscribe'));
        $manager->drainQueue();
        $id = $manager->agentSignalHolds()->getWatermark();
        self::assertCount(1, $manager->heldFrames());
        $manager->reportStopped($agent);
        $manager->drainQueue();
        self::assertSame($id, $manager->agentSignalHolds()->getWatermark());
        self::assertCount(1, $manager->heldFrames());
        self::assertSame($order, $manager->heldFrames()[0]->recovery->origin);
        self::assertSame([], $manager->pageErrorFrames());
    }

    /** @return iterable<string, array{bool, bool}> Admission of the younger update relative to stop recovery */
    public static function refreezeUpdateAdmissions(): iterable
    {
        yield 'already parked' => [true, false];
        yield 'parked during client lookup' => [true, true];
        yield 'terminal update' => [false, false];
    }

    #[DataProvider('refreezeUpdateAdmissions')]
    public function testRefreezeRecoversAnIncompleteUpdateWithoutAbandoningTheYoungerReplay(bool $younger, bool $duringLookup): void
    {
        $manager = new HeldAgentSignalTestManager();
        [$placement] = $this->recoveringPlacement($manager);
        $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
        $page = HeldAgentSignalTestRouter::ORDERED_PAGE;
        $index = new ReflectionMethod(DaemonManager::class, 'pendingPageIndex')->invoke($manager);
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $client = new HeldAgentSignalTestWebSocket($pair[0], self::ACCEPT_KEY);
        $server = new HeldAgentSignalTestWebSocketServer('127.0.0.1', 0);
        $server->attach($client);
        $manager->registerServer($server);
        Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $page));
        try {
            $client->update($page, ['version' => 1]);
            $manager->drainQueue();
            $originalHold = $manager->heldFrames()[0];
            $accepted = $placement->localPlacementId($agent);
            $manager->workerServer->seatAgentUnderWay($agent);
            $manager->reportStarted($agent);
            $manager->workerServer->reportPlacementWorkerStarted($agent, $accepted);
            $manager->drainQueue();
            self::assertSame([$originalHold->signal->data], $manager->workerServer->updates);
            self::assertSame([], $manager->heldFrames());
            $oldToken = $originalHold->signal->data->deliveryId;
            self::assertTrue($client->isDeliveryPending($page, $oldToken));
            self::assertNotNull($index->get(self::ACCEPT_KEY, $oldToken));
            $lookup = null;
            if ($duringLookup) {
                $candidates = array_filter(iterator_to_array($index->recoveryCandidates([$agent]), false), is_int(...));
                self::assertCount(1, $candidates);
                $lookup = new ReflectionMethod(DaemonManager::class, 'pendingPageRecoveryCandidate')->invoke(
                    $manager, new SignalDispatch(null, static function (): Generator { yield; }), reset($candidates), true,
                );
                self::assertTrue($lookup->valid());
            }

            if ($younger) {
                $next = new PlacementId(self::FIRST_PLACING_LEADER, self::REPLAY_TERM, 2);
                $placement->onPlaceAgent(self::FIRST_PLACING_LEADER, new PeerPlaceAgentDTO($agent, null, $next));
                $placement->beginLocalPlacementRecovery($agent, $next);
                $client->update($page, ['version' => 2]);
                $manager->drainQueue();
                $holdId = $manager->agentSignalHolds()->getWatermark();
                $held = $manager->heldFrames()[0];
                self::assertSame(['version' => 2], $held->signal->data->params);
                $token = $held->signal->data->deliveryId;
                self::assertTrue($client->isDeliveryPending($page, $token));
                self::assertNotNull($index->get(self::ACCEPT_KEY, $token));
            }
            if ($lookup !== null) {
                $lookup->next();
                self::assertFalse($lookup->valid());
            } else {
                $manager->reportStopped($agent);
            }
            $manager->drainQueue();

            self::assertFalse($client->isDeliveryPending($page, $oldToken));
            self::assertNull($index->get(self::ACCEPT_KEY, $oldToken));
            self::assertInstanceOf(HeldAgentSignalTestRouter::class, Hilos::$sr);
            if (!$younger) {
                self::assertSame([['type' => SignalConstants::PAGE_RESUBSCRIBE, 'data' => ['page' => $page, 'params' => ['version' => 1]]]],
                    $client->pageRecoveryFrames);
                return;
            }

            self::assertTrue($client->isDeliveryPending($page, $token));
            self::assertNotNull($index->get(self::ACCEPT_KEY, $token));
            self::assertSame($held, $manager->agentSignalHolds()->get($holdId)?->parked);
            self::assertSame([], $client->pageRecoveryFrames);
            if (!$duringLookup) {
                $manager->workerServer->seatAgentUnderWay($agent);
                $manager->reportStarted($agent);
            }
            $manager->workerServer->reportPlacementWorkerStarted($agent, $next);
            $manager->drainQueue();
            self::assertSame([$originalHold->signal->data, $held->signal->data], $manager->workerServer->updates);
            self::assertSame([], $manager->heldFrames());
            self::assertTrue($client->isDeliveryPending($page, $token));
        } finally {
            $client->close();
            socket_close($pair[1]);
        }
    }

    public function testRefreezeKeepsTheOriginalFrameUntilAnOrdinaryPlacementStop(): void
    {
        $manager = new HeldAgentSignalTestManager();
        [$placement] = $this->recoveringPlacement($manager);
        $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
        $order = $placement->localPlacementId($agent);
        $manager->workerServer->seatAgentUnderWay($agent);
        $manager->sendToAgent($agent, null, self::MASTER_SIGNAL, new SignalData());
        $manager->drainQueue();
        $holdId = $manager->agentSignalHolds()->getWatermark();
        $recovery = $placement->placementRecovery($agent);

        $manager->onAgentStartFailed($agent, 'protected mode holds the node', $recovery);
        $manager->drainQueue();
        self::assertSame($recovery, $manager->agentSignalHolds()->get($holdId)?->parked?->recovery);
        self::assertCount(1, $manager->heldFrames());
        self::assertSame([], $manager->workerServer->deliveries);

        $placement->onStopAgent(self::FIRST_PLACING_LEADER, new PeerStopAgentDTO($agent, null, self::REPLAY_TERM, 'stop', $order));
        $manager->onAgentStartFailed($agent, 'it was stopped while waiting for its earlier stop report');
        $manager->drainQueue();
        self::assertSame([], $manager->heldFrames());
        self::assertSame([], $manager->workerServer->deliveries);
    }

    /** The placement view can name this node before the leader's start order reaches it. */
    public function testAFrameFromAnotherNodeAheadOfTheLeadersOrderWaitsAndReachesTheAgentAfterItsStart(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->placeOrderedAgent(AgentLocation::here());

        $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::ORDERED_AGENT, null, $this->forwardedSignal());
        $manager->drainQueue();

        $this->assertCount(1, $manager->heldFrames());
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacementOrder);
        $this->assertTrue($manager->heldFrames()[0]->localOnly);
        $this->assertFalse($manager->heldFrames()[0]->awaitingPlacement);
        $this->assertSame([], $manager->workerServer->deliveries);
        $this->assertSame([], $manager->refusedStarts);

        $manager->workerServer->seatAgentUnderWay(HeldAgentSignalTestRouter::ORDERED_AGENT);
        $manager->drainQueue();

        $this->assertCount(1, $manager->heldFrames());
        $this->assertFalse($manager->heldFrames()[0]->awaitingPlacementOrder);
        $this->assertTrue($manager->heldFrames()[0]->localOnly);
        $this->assertSame([], $manager->workerServer->deliveries);

        $manager->reportStarted(HeldAgentSignalTestRouter::ORDERED_AGENT);
        $manager->drainQueue();
        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame(
            [self::FORWARDED_SIGNAL . '@' . HeldAgentSignalTestRouter::ORDERED_AGENT],
            $manager->workerServer->deliveries,
        );
        $this->assertSame([], $manager->refusedStarts);
    }

    /** A frame originating here waits on the same order and start report. */
    public function testAFrameOfThisNodeAheadOfTheLeadersOrderWaitsTheSameWay(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->placeOrderedAgent(AgentLocation::here());
        $this->queuePush(HeldAgentSignalTestRouter::ORDERED_PUSH);
        $manager->drainQueue();

        $this->assertCount(1, $manager->heldFrames());
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacementOrder);
        $this->assertFalse($manager->heldFrames()[0]->localOnly);
        $this->assertFalse($manager->heldFrames()[0]->awaitingPlacement);
        $this->assertSame([], $manager->workerServer->deliveries);
        $this->assertSame([], $manager->refusedStarts);

        $manager->workerServer->seatAgentUnderWay(HeldAgentSignalTestRouter::ORDERED_AGENT);
        $manager->drainQueue();

        $this->assertCount(1, $manager->heldFrames());
        $this->assertFalse($manager->heldFrames()[0]->awaitingPlacementOrder);
        $this->assertFalse($manager->heldFrames()[0]->localOnly);
        $this->assertSame([], $manager->workerServer->deliveries);

        $manager->reportStarted(HeldAgentSignalTestRouter::ORDERED_AGENT);
        $manager->drainQueue();
        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame(
            [HeldAgentSignalTestRouter::ORDERED_PUSH . '@' . HeldAgentSignalTestRouter::ORDERED_AGENT],
            $manager->workerServer->deliveries,
        );
        $this->assertSame([], $manager->refusedStarts);
    }

    /** A local frame waiting for an order returns to placement when the view moves its agent. */
    public function testAFrameOfThisNodeWaitingForTheOrderGoesWhereTheViewMovesTheAgent(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->placeOrderedAgent(AgentLocation::here());
        $this->queuePush(HeldAgentSignalTestRouter::ORDERED_PUSH);
        $manager->drainQueue();

        $this->assertCount(1, $manager->heldFrames());
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacementOrder);
        $manager->placedReleases = [];

        $this->placeOrderedAgent(AgentLocation::onNode(self::OTHER_NODE));
        new ReflectionMethod(SignalDispatcher::class, 'queueAgentDiscovery')
            ->invoke(SignalDispatcherFixture::owner($manager), HeldAgentSignalTestRouter::ORDERED_AGENT, true);
        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame([HeldAgentSignalTestRouter::ORDERED_AGENT], $manager->placedReleases);
        $this->assertSame([], $manager->workerServer->deliveries);
        $this->assertSame([], $manager->refusedStarts);
    }

    /** A forwarded frame ahead of the local view is held before the placement ask can answer. */
    public function testAFrameFromAnotherNodeAheadOfTheViewWaitsOnAPlacementVerdict(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->placeOrderedAgent(AgentLocation::unknown());

        $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::ORDERED_AGENT, null, $this->forwardedSignal());

        $this->assertCount(1, $manager->heldFrames());
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacement);
        $this->assertTrue($manager->heldFrames()[0]->localOnly);
        $this->assertFalse($manager->heldFrames()[0]->awaitingPlacementOrder);
        $this->assertSame(1, $manager->placementAsks);
        $this->assertSame([], $manager->workerServer->deliveries);
        $this->assertSame([], $manager->refusedStarts);
        $manager->drainQueue();

        $this->placeOrderedAgent(AgentLocation::here());
        new ReflectionMethod(SignalDispatcher::class, 'queueAgentDiscovery')
            ->invoke(SignalDispatcherFixture::owner($manager), HeldAgentSignalTestRouter::ORDERED_AGENT, true);
        $manager->drainQueue();

        $this->assertCount(1, $manager->heldFrames());
        $this->assertFalse($manager->heldFrames()[0]->awaitingPlacement);
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacementOrder);
        $this->assertTrue($manager->heldFrames()[0]->localOnly);
        $this->assertSame([], $manager->workerServer->deliveries);

        $manager->workerServer->seatAgentUnderWay(HeldAgentSignalTestRouter::ORDERED_AGENT);
        $manager->drainQueue();

        $this->assertCount(1, $manager->heldFrames());
        $this->assertFalse($manager->heldFrames()[0]->awaitingPlacementOrder);
        $this->assertSame([], $manager->workerServer->deliveries);

        $manager->reportStarted(HeldAgentSignalTestRouter::ORDERED_AGENT);
        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame(
            [self::FORWARDED_SIGNAL . '@' . HeldAgentSignalTestRouter::ORDERED_AGENT],
            $manager->workerServer->deliveries,
        );
        $this->assertSame([], $manager->refusedStarts);
    }

    /** A forwarded frame for another host is dropped without recording a failed local start. */
    public function testAFrameFromAnotherNodeForAnAgentPlacedElsewhereIsDroppedWithoutARefusedStart(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->placeOrderedAgent(AgentLocation::onNode(self::OTHER_NODE));

        $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::ORDERED_AGENT, null, $this->forwardedSignal());
        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame([], $manager->workerServer->deliveries);
        $this->assertSame([], $manager->placedReleases);
        $this->assertSame([], $manager->refusedStarts);
    }

    /**
     * A close for an agent the freeze stopped is held, and the hold does not start that agent.
     *
     * The walk is about to put the agent back into the worker that still keeps the tab, so a start
     * from this frame would seat it somewhere else (HIL-1208).
     */
    public function testAConnectionCloseForAnAgentTheFreezeStoppedIsHeldWithoutStartingIt(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $manager->workerServer->awaitingResume = [HeldAgentSignalTestRouter::FROZEN_AGENT];
        $this->queueFrozenClose();

        $manager->drainQueue();

        $this->assertCount(1, $manager->heldFrames());
        $this->assertTrue($manager->heldFrames()[0]->awaitingResume);
        $this->assertSame([], $manager->workerServer->deliveries);
        $this->assertNotContains(
            HeldAgentSignalTestRouter::FROZEN_AGENT,
            $manager->workerServer->startedAgentTypes,
        );
    }

    /**
     * Once the lift has asked for the agent, the held close waits on the start report and then goes out.
     */
    public function testTheHeldCloseReachesTheAgentOnceTheLiftBringsItBack(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $manager->workerServer->awaitingResume = [HeldAgentSignalTestRouter::FROZEN_AGENT];
        $this->queueFrozenClose();
        $manager->drainQueue();

        $manager->workerServer->finishResume();
        $manager->workerServer->seatAgentUnderWay(HeldAgentSignalTestRouter::FROZEN_AGENT);
        $manager->drainQueue();

        $this->assertCount(1, $manager->heldFrames());
        $this->assertFalse($manager->heldFrames()[0]->awaitingResume);

        $manager->reportStarted(HeldAgentSignalTestRouter::FROZEN_AGENT);
        $manager->drainQueue();

        $this->assertSame(
            [HeldAgentSignalTestRouter::FROZEN_CLOSE . '@' . HeldAgentSignalTestRouter::FROZEN_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * A lift that passes the agent without bringing it back here drops the held close.
     */
    public function testAHeldCloseIsDroppedWhenTheLiftDoesNotBringItsAgentBack(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $manager->workerServer->awaitingResume = [HeldAgentSignalTestRouter::FROZEN_AGENT];
        $this->queueFrozenClose();
        $manager->drainQueue();

        $manager->workerServer->finishResume();
        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame([], $manager->workerServer->deliveries);
    }

    /**
     * Any frame but a close still reaches the delivery that drops it, the way a refused start always has.
     */
    public function testAnyOtherFrameForAFrozenAgentIsStillNotHeld(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $manager->workerServer->awaitingResume = [HeldAgentSignalTestRouter::FROZEN_AGENT];
        $this->queuePush(HeldAgentSignalTestRouter::FROZEN_PUSH);

        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame(
            [HeldAgentSignalTestRouter::FROZEN_PUSH . '@' . HeldAgentSignalTestRouter::FROZEN_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * A close that already crossed the mesh is held with that mark, and the release delivers it here.
     */
    public function testACloseFromAnotherNodeForAFrozenAgentIsHeldWithTheMeshMark(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $manager->workerServer->awaitingResume = [HeldAgentSignalTestRouter::FROZEN_AGENT];

        $manager->deliverSignalToAgent(
            HeldAgentSignalTestRouter::FROZEN_AGENT,
            null,
            $this->frozenCloseSignal(),
        );

        $this->assertCount(1, $manager->heldFrames());
        $this->assertTrue($manager->heldFrames()[0]->localOnly);
        $this->assertTrue($manager->heldFrames()[0]->awaitingResume);

        $manager->workerServer->finishResume();
        $manager->workerServer->seatAgentUnderWay(HeldAgentSignalTestRouter::FROZEN_AGENT);
        $manager->drainQueue();
        $this->assertCount(1, $manager->heldFrames());
        $this->assertFalse($manager->heldFrames()[0]->awaitingResume);

        $manager->reportStarted(HeldAgentSignalTestRouter::FROZEN_AGENT);
        $manager->drainQueue();

        $this->assertSame(
            [HeldAgentSignalTestRouter::FROZEN_CLOSE . '@' . HeldAgentSignalTestRouter::FROZEN_AGENT],
            $manager->workerServer->deliveries,
        );
        $this->assertSame([], $manager->placedReleases);
    }

    /**
     * An agent no node is known to host is waited for too, instead of answered at once - and it
     * is the one wait a placement verdict ends. The answer is the one a dropped subscribe always
     * got, only later, and the frame is not held a second time.
     */
    public function testAFrameForAnAgentWithNoAddressIsHeldAndAnsweredOnlyWhenTheWaitEnds(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);

        $manager->drainQueue();
        $this->assertSame([], $manager->pageErrorFrames());
        $this->assertCount(1, $manager->heldFrames());
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacement);

        $manager->reportNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT);

        $frames = $manager->pageErrorFrames();
        $this->assertCount(1, $frames);
        $error = $frames[0]->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame('node_unreachable', $error->errorCode);
        $this->assertSame([], $manager->heldFrames());
        $this->assertSame([], $manager->workerServer->deliveries);
    }

    /**
     * A missing address is not a six-second wait: the old ceiling passing leaves the frame held,
     * and a placed verdict is what lets it go - the view names a node, and the frame leaves
     * through the placing door (HIL-1041).
     */
    public function testAnAddresslessHoldOutlastsTheOldCeilingAndLeavesWhenANodeIsNamed(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);
        $manager->drainQueue();
        $manager->placedReleases = [];

        $manager->ageHeldFrames(self::LONG_START_SECONDS);
        $manager->drainQueue();

        $this->assertSame([], $manager->pageErrorFrames());
        $this->assertCount(1, $manager->heldFrames());
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacement);

        $this->placeOnAnotherNode(HeldAgentSignalTestRouter::COLD_AGENT);
        new ReflectionMethod(SignalDispatcher::class, 'queueAgentDiscovery')
            ->invoke(SignalDispatcherFixture::owner($manager), HeldAgentSignalTestRouter::COLD_AGENT, true);
        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame([HeldAgentSignalTestRouter::COLD_AGENT], $manager->placedReleases);
    }

    /** @return iterable<string, array{string}> Every final negative placement outcome */
    public static function negativeVerdicts(): iterable
    {
        yield 'failed singleton' => ['failed: no worker'];
        yield 'unplaced singleton' => ['unplaced: no capable node'];
        yield 'refused singleton' => ['refused: another RT owner'];
    }

    /** @param string $reason Final negative verdict received for the singleton */
    #[DataProvider('negativeVerdicts')]
    public function testANotPlacedVerdictAnswersTheWaitingFrameAtOnce(string $reason): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);
        $manager->drainQueue();

        $manager->reportNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT, $reason);

        $frames = $manager->pageErrorFrames();
        $this->assertCount(1, $frames);
        $error = $frames[0]->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame('node_unreachable', $error->errorCode);
        $this->assertSame([], $manager->heldFrames());
    }

    public function testAPlacementVerdictCanExpireBetweenItsQueuedAnnouncementsAndPhysicalSend(): void
    {
        $this->declareGoneListeners(AgentsGoneBothTestHilos::class);
        $manager = new HeldAgentSignalTestManager();
        foreach ([AgentsGoneTestListener::TYPE, OAuthAgentsGoneTestListener::TYPE] as $recipient) {
            $manager->workerServer->seatAgentUnderWay($recipient);
            $manager->reportStarted($recipient);
        }
        $current = true;
        $guard = static function () use (&$current): bool { return $current; };
        $manager->onAgentNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT, null, 'unplaced: no fit', current: $guard);
        self::assertSame(2, Hilos::$sr->getQueuedSignalCount());
        $current = false;
        $manager->drainQueue();
        self::assertSame([], $manager->workerServer->deliveries);

        $current = true;
        $manager->onAgentNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT, null, 'unplaced: no fit', current: $guard);
        $manager->drainQueue();
        self::assertSame([
            HilosSignalConstants::HILOS_AGENTS_GONE . '@' . AgentsGoneTestListener::TYPE,
            HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE . '@' . OAuthAgentsGoneTestListener::TYPE,
        ], $manager->workerServer->deliveries);
    }

    public function testAnAdmittedPlacementRefusalLosesAuthorityWhileWaitingForItsEffect(): void
    {
        $this->declareGoneListeners(AgentsGoneBothTestHilos::class);
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);
        $manager->drainQueue();
        $held = $manager->heldFrames();
        self::assertCount(1, $held);

        SignalDispatcherFixture::owner($manager)->queueSignalEffect(static function (): Generator {
            yield;
        }, null);
        $current = true;
        $manager->onAgentNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT, null, 'unplaced: no fit',
            current: static function () use (&$current): bool { return $current; });
        $current = false;
        $manager->drainQueue();

        self::assertSame($held, $manager->heldFrames());
        self::assertSame([], $manager->pageErrorFrames());
        self::assertSame([], $manager->workerServer->deliveries);
    }

    /**
     * @param bool $answerFirst Whether the first candidate answers before the decision is superseded
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testPlacementRefusalRechecksItsAuthorityAroundEachClientLookup(bool $answerFirst): void
    {
        $manager = new HeldAgentSignalTestManager();
        $agent = HeldAgentSignalTestRouter::COLD_AGENT;
        $page = HeldAgentSignalTestRouter::COLD_PAGE;
        foreach (['first', 'second'] as $acceptKey) {
            $manager->deliverSignalToAgent($agent, null, new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                new SignalName($page), new WebSocketPageSubscribeSignalDTO($acceptKey, $page),
            ));
        }
        self::assertCount(2, $manager->heldFrames());
        $manager->registerServer(new HeldAgentSignalTestWebSocketServer('127.0.0.1', 0));

        $current = true;
        $guard = static function () use (&$current): bool { return $current; };
        $dispatch = SignalDispatcherFixture::direct();
        $candidates = $manager->agentSignalHolds()->agentCandidates($agent);
        $work = new ReflectionMethod(DaemonManager::class, 'refuseHeldSignalCandidates')->invoke(
            $manager, $dispatch, $candidates, ' no fit', 'no fit', true, true, null, $guard,
        );
        $work->rewind();
        $work->next();
        self::assertSame('first', $dispatch->clientAcceptKey);
        self::assertFalse($dispatch->clientResolved);
        if ($answerFirst) {
            $work->next();
            self::assertTrue($dispatch->clientResolved);
            self::assertCount(1, $manager->pageErrorFrames());
        }
        $current = false;
        $work->next();
        self::assertFalse($work->valid());
        self::assertCount($answerFirst ? 1 : 2, $manager->heldFrames());
        self::assertCount($answerFirst ? 1 : 0, $manager->pageErrorFrames());
    }

    public function testPlacementLossRechecksAuthorityBetweenTheTwoAnnouncementQueues(): void
    {
        $this->declareGoneListeners(AgentsGoneBothTestHilos::class);
        $manager = new HeldAgentSignalTestManager();
        $current = true;
        $guard = static function () use (&$current): bool { return $current; };
        $work = new ReflectionMethod(DaemonManager::class, 'agentsGoneAnnouncement')->invoke(
            $manager, [HeldAgentSignalTestRouter::COLD_AGENT], AgentsGoneSignalData::REASON_NOT_PLACED, $guard,
        );
        $work->rewind();
        $work->next();
        $work->next();
        self::assertSame(1, Hilos::$sr->getQueuedSignalCount());

        $current = false;
        $work->next();
        self::assertFalse($work->valid());
        $announcements = $this->namedGoneFrames();
        self::assertSame([HilosSignalConstants::HILOS_AGENTS_GONE], array_keys($announcements));
        self::assertFalse(Hilos::$sr->isSignalApplicable($announcements[HilosSignalConstants::HILOS_AGENTS_GONE]));
    }

    /** The real hold-before-ask path receives its negative verdict on the asking stack. */
    public function testSynchronousPlacementRefusalKeepsTheOriginalPrefixAndCohort(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $manager->leaderVerdict = 'unplaced: no capable node';
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);
        $owner = SignalDispatcherFixture::owner($manager);
        $state = $owner->signalDispatchState();
        $boundary = new ReflectionMethod(DaemonManager::class, 'newSignalDispatchPrefix')->invoke($manager);
        $state->browsers = $boundary;
        $answered = 0;
        $manager->afterPlacementVerdict = static function () use ($manager, $state, $boundary, &$answered): void {
            ++$answered;
            self::assertCount(1, $manager->heldFrames(), 'The hold must precede the synchronous ask and its deferred effect');
            $origin = $state->active;
            self::assertNotNull($origin);
            self::assertCount(1, $state->effects, 'A nested verdict is queued once without recursively advancing the current generator');
            $effect = $state->effects[array_key_last($state->effects)];
            self::assertNotSame($origin, $effect);
            self::assertNull($effect->getSignal());
            self::assertNotNull($origin->effectCohortId);
            self::assertSame($origin->effectCohortId, $effect->effectCohortId);
            self::assertSame($boundary->id, $effect->browserPrefixId);
            self::assertFalse($boundary->isReadyForAccounting(), 'The refusal and held frame still owe this original prefix');
            self::assertSame([], $manager->pageErrorFrames(), 'The child executes in its own scheduler portion');
        };
        for ($portion = 0; $portion < 128 && !$boundary->complete; $portion++) {
            $manager->drainQueue();
        }
        self::assertSame(1, $answered);
        self::assertSame(1, $manager->placementAsks);
        self::assertCount(1, $manager->pageErrorFrames());
        self::assertSame('node_unreachable', $manager->pageErrorFrames()[0]->data->errorCode);
        self::assertSame([], $manager->heldFrames());
        self::assertSame([], $state->effects);
        self::assertSame([], $state->effectCohortCounts);
        self::assertTrue($boundary->complete);
        self::assertFalse($boundary->failed);
        $manager->afterPlacementVerdict = null;
    }

    /** An unchanged hold is idle; an actual placement fact permits another ask and its final verdict. */
    public function testAWaitingFrameAsksAgainOnlyOnPlacementProgress(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);
        $manager->drainQueue();
        $asksAfterPark = $manager->placementAsks;

        $manager->drainQueue();
        $manager->drainQueue();

        $this->assertSame($asksAfterPark, $manager->placementAsks);
        $this->assertCount(1, $manager->heldFrames());
        $this->assertSame([], $manager->pageErrorFrames());
        $manager->leaderVerdict = 'unplaced: no capable node';
        $this->leaveUnplaced(HeldAgentSignalTestRouter::COLD_AGENT);
        new ReflectionMethod(SignalDispatcher::class, 'queueAgentDiscovery')
            ->invoke(SignalDispatcherFixture::owner($manager), HeldAgentSignalTestRouter::COLD_AGENT, true);
        $manager->drainQueue();
        $this->assertSame($asksAfterPark + 1, $manager->placementAsks);
        $this->assertCount(1, $manager->pageErrorFrames());
        $this->assertSame([], $manager->heldFrames());
    }

    /**
     * The worker running the agent dies before the start it was running is reported. Nothing is
     * coming any more, so this is the end of the wait, and the hold has no deadline behind it to
     * end it later - the page is answered here or never.
     */
    public function testTheDeathOfTheWorkerAnswersWhatWasHeldForItsAgent(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::COLD_PAGE);
        $manager->drainQueue();
        $this->assertCount(1, $manager->heldFrames());

        $manager->reportWorkerLost(HeldAgentSignalTestRouter::COLD_AGENT);

        $frames = $manager->pageErrorFrames();
        $this->assertCount(1, $frames);
        $error = $frames[0]->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame('agent_unavailable', $error->errorCode);
        $this->assertSame([], $manager->heldFrames());
    }

    /**
     * Answered and not put back: a start that took its worker down would take the next one down
     * the same way, so the frame must not be waiting for whatever comes up in its place.
     */
    public function testAFrameAnsweredForALostWorkerIsNotRedelivered(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queuePush(HeldAgentSignalTestRouter::COLD_PUSH);
        $manager->drainQueue();

        $manager->reportWorkerLost(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();

        $this->assertSame([], $manager->workerServer->deliveries);
    }

    /**
     * The report names the agents of one worker, and a frame waiting on an agent of another one
     * is somebody else's wait.
     */
    public function testTheDeathOfAWorkerLeavesFramesHeldForOtherAgentsAlone(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queuePush(HeldAgentSignalTestRouter::COLD_PUSH);
        $manager->drainQueue();

        $manager->reportWorkerLost(HeldAgentSignalTestRouter::FROZEN_AGENT);

        $this->assertCount(1, $manager->heldFrames());
        $this->assertSame([], $manager->pageErrorFrames());
    }

    /**
     * A stop of the agent whose start is still running is the fifth end of the hold: the frames
     * are let go, not answered. The ordinary door then starts the agent again, and the frame waits
     * for that start the way it waited for the first (HIL-1041).
     */
    public function testStoppingAnAgentWhoseStartIsUnderWayLetsTheFrameAskAgain(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::COLD_PAGE);
        $manager->drainQueue();
        $this->assertCount(1, $manager->heldFrames());

        $manager->reportStopped(HeldAgentSignalTestRouter::COLD_AGENT);

        $this->assertSame([], $manager->pageErrorFrames());
        $this->assertSame([], $manager->heldFrames());
        $this->assertFalse($manager->holdsRecordOf(HeldAgentSignalTestRouter::COLD_AGENT));

        $manager->drainQueue();

        $this->assertSame([], $manager->pageErrorFrames());
        $this->assertSame(
            [HeldAgentSignalTestRouter::COLD_AGENT, HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->startedAgentTypes,
        );
        $this->assertCount(1, $manager->heldFrames());
        $this->assertSame([], $manager->workerServer->deliveries);

        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();

        $this->assertSame(
            [HeldAgentSignalTestRouter::COLD_PAGE . '@' . HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * A stop that leaves the agent with no address is not a refusal: the frame asks for a
     * placement and waits on the verdict, the way the walk already does for a first delivery
     * that met nobody (HIL-1041).
     */
    public function testStoppingAnAgentWithNoAddressLetsTheFrameWaitOnAVerdict(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::COLD_PAGE);
        $manager->drainQueue();
        $this->assertCount(1, $manager->heldFrames());
        $this->assertFalse($manager->heldFrames()[0]->awaitingPlacement);

        $manager->reportStopped(HeldAgentSignalTestRouter::COLD_AGENT);
        $this->leaveUnplaced(HeldAgentSignalTestRouter::COLD_AGENT);
        $asks = $manager->placementAsks;
        $manager->drainQueue();

        $this->assertSame([], $manager->pageErrorFrames());
        $this->assertCount(1, $manager->heldFrames());
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacement);
        $this->assertSame($asks + 1, $manager->placementAsks);

        $manager->reportNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT);
        $this->assertCount(1, $manager->pageErrorFrames());
        $this->assertSame([], $manager->heldFrames());
    }

    /**
     * On the leader the verdict of its own ask comes back inside the ask (HIL-1041). The walk holds
     * the frame before asking, so the verdict finds it there: answered once, nothing left held.
     *
     * @param string $reason Final negative verdict received for the singleton
     */
    #[DataProvider('negativeVerdicts')]
    public function testALeaderVerdictInsideTheWalksAskAnswersTheFrameOnce(string $reason): void
    {
        $manager = new HeldAgentSignalTestManager();
        $manager->leaderVerdict = $reason;
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);

        $manager->drainQueue();
        $this->assertCount(1, $manager->pageErrorFrames());
        $this->assertSame([], $manager->heldFrames());

        $manager->drainQueue();
        $this->assertCount(1, $manager->pageErrorFrames());
    }

    /**
     * The same verdict inside a release pass: the pass asks only once the holds are written back,
     * so the frame the verdict answers is not put back to be answered again on every pass.
     *
     * @param string $reason Final negative verdict received for the singleton
     */
    #[DataProvider('negativeVerdicts')]
    public function testALeaderVerdictInsideTheReleaseAskAnswersTheFrameOnce(string $reason): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);
        $manager->drainQueue();
        $this->assertCount(1, $manager->heldFrames());

        $manager->leaderVerdict = $reason;
        $this->leaveUnplaced(HeldAgentSignalTestRouter::COLD_AGENT);
        new ReflectionMethod(SignalDispatcher::class, 'queueAgentDiscovery')
            ->invoke(SignalDispatcherFixture::owner($manager), HeldAgentSignalTestRouter::COLD_AGENT, true);
        $manager->drainQueue();
        $this->assertCount(1, $manager->pageErrorFrames());
        $this->assertSame([], $manager->heldFrames());

        $manager->drainQueue();
        $this->assertCount(1, $manager->pageErrorFrames());
    }

    /**
     * A frame a stop let go that meets no address again is held before its ask as well, so the
     * leader's verdict answers it in place rather than leaving it held behind the answer.
     *
     * @param string $reason Final negative verdict received for the singleton
     */
    #[DataProvider('negativeVerdicts')]
    public function testALeaderVerdictAfterAStopAnswersTheReleasedFrameOnce(string $reason): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::COLD_PAGE);
        $manager->drainQueue();

        $manager->reportStopped(HeldAgentSignalTestRouter::COLD_AGENT);
        $this->leaveUnplaced(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->leaderVerdict = $reason;
        $manager->drainQueue();
        $this->assertCount(1, $manager->pageErrorFrames());
        $this->assertSame([], $manager->heldFrames());

        $manager->drainQueue();
        $this->assertCount(1, $manager->pageErrorFrames());
    }

    /**
     * A command held for a starting agent outlives its caller now that the hold has no clock, so
     * the caller leaving has to be a fact of its own - and it drops the frame without a word,
     * because the one who would read the refusal is exactly who has gone.
     */
    public function testACommandWhoseCallerStoppedWaitingIsDroppedWithoutAnAnswer(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueCommand(HeldAgentSignalTestRouter::COLD_COMMAND);
        $manager->drainQueue();
        $this->assertCount(1, $manager->heldFrames());

        $manager->onCommandAbandoned(self::CORRELATION_ID);

        $this->assertSame([], $manager->heldFrames());

        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();
        $this->assertSame([], $manager->workerServer->deliveries);
    }

    /** @param bool $http Whether the still-queued request came from HTTP */
    #[DataProvider('releasedRequestKinds')]
    public function testAbandonmentBeforeOrdinaryAdmissionCannotStartTheOperation(bool $http): void
    {
        $manager = new HeldAgentSignalTestManager();
        if ($http) {
            $this->queueHttpRequest(HeldAgentSignalTestRouter::COLD_HTTP_PATH);
        } else {
            $this->queueCommand(HeldAgentSignalTestRouter::COLD_COMMAND);
        }
        $manager->onCommandAbandoned(self::CORRELATION_ID);
        $manager->drainQueue();
        $manager->drainQueue();
        self::assertSame([], $manager->workerServer->startedAgentTypes);
        self::assertSame([], $manager->workerServer->deliveries);
        self::assertSame([], $manager->heldFrames());
        self::assertFalse(Hilos::$sr->hasQueuedSignals());
        $state = SignalDispatcherFixture::owner($manager)->retainedState();
        self::assertSame([], $state->commandCancellations);
        self::assertSame([], $state->abandonedCommands);
    }

    public function testCancellationCoversTheRetiredTailWithoutCancelingANewRouterRequest(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueCommand(HeldAgentSignalTestRouter::COLD_COMMAND);
        $manager->onCommandAbandoned(self::CORRELATION_ID);
        Hilos::$sr = new HeldAgentSignalTestRouter();
        $this->queueCommand(HeldAgentSignalTestRouter::COLD_COMMAND);
        for ($portion = 0; $portion < 10; $portion++) {
            $manager->drainQueue();
        }
        self::assertCount(1, $manager->heldFrames(), 'Only the new-router request survives the finite cancellation');
        $state = SignalDispatcherFixture::owner($manager)->retainedState();
        self::assertSame(0, $state->retiredRemaining);
        self::assertSame([], $state->commandCancellations);
        self::assertSame([], $state->abandonedCommands);
        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();
        self::assertSame([HeldAgentSignalTestRouter::COLD_COMMAND . '@' . HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->deliveries);
    }

    /** @return iterable<string, array{?string}> Exact page and whole-connection cancellation */
    public static function pageCancellationScopes(): iterable
    {
        yield 'page' => [HeldAgentSignalTestRouter::COLD_PAGE];
        yield 'connection' => [null];
    }

    /** @param ?string $page Page being left, absent for the whole connection */
    #[DataProvider('pageCancellationScopes')]
    public function testPageCancellationInvalidatesItsCohortBehindAnOlderRefusalWithoutSynchronousRelease(?string $page): void
    {
        $manager = new HeldAgentSignalTestManager();
        for ($index = 0; $index < 260; $index++) {
            $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                new SignalName(HeldAgentSignalTestRouter::COLD_PAGE),
                new WebSocketPageSubscribeSignalDTO($index < 130 ? 'live-' . $index : self::ACCEPT_KEY,
                    HeldAgentSignalTestRouter::COLD_PAGE),
            ));
        }
        $last = WeakReference::create($manager->heldFrames()[259]->signal->data);
        $manager->reportNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT);
        new ReflectionClass(DaemonManager::class)->getMethod('cancelPendingPageDeliveries')->invoke(
            $manager, SignalDispatcherFixture::direct(), self::ACCEPT_KEY, $page);
        self::assertGreaterThan(130, count($manager->heldFrames()), 'Cancellation must not synchronously settle its whole indexed cohort');
        self::assertNotNull($last->get());
        $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(HeldAgentSignalTestRouter::COLD_PAGE),
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, HeldAgentSignalTestRouter::COLD_PAGE, ['new' => true]),
        ));
        $runnable = new ReflectionClass(DaemonManager::class)->getMethod('hasRunnableSignalDispatch');
        for ($portion = 0; $portion < 1000 && $runnable->invoke($manager); $portion++) {
            $manager->drainQueue();
        }
        self::assertFalse($runnable->invoke($manager));
        self::assertNull($last->get());
        self::assertCount(1, $manager->heldFrames());
        self::assertSame(['new' => true], $manager->heldFrames()[0]->signal->data->params);
        self::assertSame(array_map(static fn(int $index): string => 'live-' . $index, range(0, 129)),
            array_map(static fn(WebSocketSignalData $frame): ?string => $frame->targetAcceptKey, $manager->pageErrorFrames()));
        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();
        self::assertSame([], $manager->heldFrames());
        self::assertSame([HeldAgentSignalTestRouter::COLD_PAGE . '@' . HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->deliveries);
    }

    public function testCancellationInvalidatesAHeldCohortBeforeAnOlderRefusalCursorReachesIt(): void
    {
        $manager = new HeldAgentSignalTestManager();
        for ($index = 0; $index < 260; $index++) {
            $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, new SignalDTO(
                new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::COMMAND_REQUEST),
                new SignalName(HeldAgentSignalTestRouter::COLD_COMMAND),
                new CommandRequestDTO($index < 130 ? 'live-' . $index : self::CORRELATION_ID, HeldAgentSignalTestRouter::COLD_COMMAND),
            ));
        }
        $manager->reportNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT);
        self::assertGreaterThan(130, count($manager->heldFrames()));
        $manager->onCommandAbandoned(self::CORRELATION_ID);
        $dispatch = new ReflectionClass(DaemonManager::class)->getMethod('dispatchSignals');
        $runnable = new ReflectionClass(DaemonManager::class)->getMethod('hasRunnableSignalDispatch');
        for ($portion = 0; $portion < 1000 && $runnable->invoke($manager); $portion++) {
            $dispatch->invoke($manager);
        }
        self::assertFalse($runnable->invoke($manager));
        self::assertSame([], $manager->heldFrames());
        self::assertSame(array_map(static fn(int $index): string => 'live-' . $index, range(0, 129)),
            Hilos::$sr->returnedCorrelations);
        $state = SignalDispatcherFixture::owner($manager)->retainedState();
        self::assertSame([], $state->commandCancellations);
        self::assertSame([], $state->abandonedCommands);
    }

    public function testReentrantAbandonmentKeepsIndependentWorkRaisedByTheSameHook(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $dispatch = new SignalDispatch(new SignalDTO(new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST), new SignalName(HeldAgentSignalTestRouter::COLD_COMMAND),
            new CommandRequestDTO(self::CORRELATION_ID, HeldAgentSignalTestRouter::COLD_COMMAND)),
            static function (SignalDispatch $dispatch) use ($manager): Generator {
                yield;
                $manager->onCommandAbandoned(self::CORRELATION_ID, $dispatch);
                $manager->sendToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, 'independent_after_abandon', new SignalData());
                yield;
            });
        new ReflectionClass(DaemonManager::class)->getMethod('signalDispatcher')->invoke($manager)->runSignalPortion($dispatch, 1);
        self::assertSame(SignalDispatchPhase::Canceled, $dispatch->phase);
        self::assertCount(1, $manager->heldFrames());
        self::assertSame('independent_after_abandon', $manager->heldFrames()[0]->signal->signalName->getName());
        $manager->drainQueue();
        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();
        self::assertSame(['independent_after_abandon@' . HeldAgentSignalTestRouter::COLD_AGENT], $manager->workerServer->deliveries);
    }

    /**
     * A start report releases a request before the next drain. Its caller can leave in that
     * interval, while the request still belongs to the master and can be canceled.
     *
     * @param bool $http Whether the held request came from an HTTP connection
     */
    #[DataProvider('releasedRequestKinds')]
    public function testAReleasedRequestWhoseCallerLeftIsDroppedWithoutAnAnswer(bool $http): void
    {
        $manager = new HeldAgentSignalTestManager();
        if ($http) {
            $this->queueHttpRequest(HeldAgentSignalTestRouter::COLD_HTTP_PATH);
        } else {
            $this->queueCommand(HeldAgentSignalTestRouter::COLD_COMMAND);
        }
        $manager->drainQueue();
        $this->assertCount(1, $manager->heldFrames());

        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $this->assertSame([], $manager->heldFrames());
        $this->assertSame([], $manager->workerServer->deliveries);
        $manager->onCommandAbandoned(self::CORRELATION_ID);

        $manager->drainQueue();
        $manager->drainQueue();
        $this->assertSame([], $manager->workerServer->deliveries);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'Cancellation owes no refusal to the departed caller.');
    }

    /**
     * Cancellation remains correlation-specific after a start report has released the request.
     *
     * @param bool $http Whether the held request came from an HTTP connection
     */
    #[DataProvider('releasedRequestKinds')]
    public function testAnotherCallerLeavingDoesNotCancelAReleasedRequest(bool $http): void
    {
        $manager = new HeldAgentSignalTestManager();
        if ($http) {
            $this->queueHttpRequest(HeldAgentSignalTestRouter::COLD_HTTP_PATH);
        } else {
            $this->queueCommand(HeldAgentSignalTestRouter::COLD_COMMAND);
        }
        $manager->drainQueue();
        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->onCommandAbandoned('corr-somebody-else');

        $manager->drainQueue();
        $manager->drainQueue();
        $this->assertSame(
            [($http ? HttpConstants::METHOD_GET . ' ' . HeldAgentSignalTestRouter::COLD_HTTP_PATH
                : HeldAgentSignalTestRouter::COLD_COMMAND) . '@' . HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    /** @param bool $http Whether the caller owns an HTTP request */
    #[DataProvider('releasedRequestKinds')]
    public function testAbandonmentCancelsTheActualReleasedCursorBetweenPortions(bool $http): void
    {
        $manager = new HeldAgentSignalTestManager();
        if ($http) {
            $this->queueHttpRequest(HeldAgentSignalTestRouter::COLD_HTTP_PATH);
        } else {
            $this->queueCommand(HeldAgentSignalTestRouter::COLD_COMMAND);
        }
        $manager->drainQueue();
        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $cursor = $manager->beginReleasedCursor();
        $manager->advanceReleasedCursor($cursor, 1);
        $this->assertTrue($cursor->hasWork());
        $this->assertSame([], $manager->workerServer->deliveries);
        $manager->onCommandAbandoned(self::CORRELATION_ID);
        $manager->advanceReleasedCursor($cursor, 64);
        $manager->drainQueue();
        $this->assertSame(SignalDispatchPhase::Canceled, $cursor->phase);
        $this->assertSame([], $manager->workerServer->deliveries);
        $this->assertSame([], $manager->heldFrames());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** @param bool $http Whether the caller owns an HTTP request */
    #[DataProvider('releasedRequestKinds')]
    public function testAnotherCallerCannotCancelAnActiveReleasedCursor(bool $http): void
    {
        $manager = new HeldAgentSignalTestManager();
        if ($http) {
            $this->queueHttpRequest(HeldAgentSignalTestRouter::COLD_HTTP_PATH);
        } else {
            $this->queueCommand(HeldAgentSignalTestRouter::COLD_COMMAND);
        }
        $manager->drainQueue();
        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $cursor = $manager->beginReleasedCursor();
        $manager->advanceReleasedCursor($cursor, 1);
        $manager->onCommandAbandoned('corr-somebody-else');
        $manager->advanceReleasedCursor($cursor, 64);
        $manager->drainQueue();
        $this->assertSame(SignalDispatchPhase::Complete, $cursor->phase);
        $this->assertSame([($http ? HttpConstants::METHOD_GET . ' ' . HeldAgentSignalTestRouter::COLD_HTTP_PATH
            : HeldAgentSignalTestRouter::COLD_COMMAND) . '@' . HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->deliveries);
    }

    /** @return array<string, array{bool}> The two request payloads sharing the abandonment door */
    public static function releasedRequestKinds(): array
    {
        return ['command' => [false], 'HTTP' => [true]];
    }

    /**
     * Two operators can be waiting on the same agent, and one giving up is not the other's word.
     */
    public function testAnotherCallerLeavingLeavesThisCommandHeld(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueCommand(HeldAgentSignalTestRouter::COLD_COMMAND);
        $manager->drainQueue();

        $manager->onCommandAbandoned('corr-somebody-else');

        $this->assertCount(1, $manager->heldFrames());
    }

    /**
     * A parked HTTP request held for a starting agent is dropped the same way when the browser
     * leaves: the HTTP server reports it through the command channel's seam (HIL-1040).
     */
    public function testAnHttpRequestWhoseBrowserLeftIsDroppedWithoutAnAnswer(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueHttpRequest(HeldAgentSignalTestRouter::COLD_HTTP_PATH);
        $manager->drainQueue();
        $this->assertCount(1, $manager->heldFrames());

        $manager->onCommandAbandoned(self::CORRELATION_ID);

        $this->assertSame([], $manager->heldFrames());
        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();
        $this->assertSame([], $manager->workerServer->deliveries);
    }

    /**
     * A start that failed answers a parked HTTP request at once with 503, as it answers a command:
     * the browser would otherwise wait for its own timeout on an agent that will not come up.
     */
    public function testAReportedStartFailureAnswersAParkedHttpRequestWith503(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueHttpRequest(HeldAgentSignalTestRouter::COLD_HTTP_PATH);
        $manager->drainQueue();

        $manager->reportStartFailed(HeldAgentSignalTestRouter::COLD_AGENT);

        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof HttpReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        $this->assertCount(1, $replies);
        $this->assertSame(self::CORRELATION_ID, $replies[0]->correlationId);
        $this->assertSame(HttpConstants::HTTP_SERVICE_UNAVAILABLE, $replies[0]->status);
        $this->assertSame([], $manager->heldFrames());
    }

    /**
     * The master's own facade used to place the agent and write into a worker with code of its
     * own, which put its signal behind a start that could still fail. It goes through the same
     * door as everything else now, so the signal waits for the start like a routed frame.
     */
    public function testASignalFromTheMasterFacadeIsHeldWhileTheAgentStarts(): void
    {
        $manager = new HeldAgentSignalTestManager();

        $manager->sendToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, self::MASTER_SIGNAL, new SignalData([]));

        $this->assertCount(1, $manager->heldFrames());
        $this->assertSame([], $manager->workerServer->deliveries);

        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();

        $this->assertSame(
            [self::MASTER_SIGNAL . '@' . HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * The frame a neighbour forwarded here is held the same way. It used to go straight into a
     * worker, because the peer transport was wired to the worker server rather than to the
     * master - so a frame that had crossed the cluster was the one frame with no hold at all.
     */
    public function testAFrameReceivedFromAnotherNodeIsHeldWhileTheAgentStarts(): void
    {
        $manager = new HeldAgentSignalTestManager();

        $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, $this->forwardedSignal());

        $this->assertCount(1, $manager->heldFrames());
        $this->assertSame([], $manager->workerServer->deliveries);

        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();

        $this->assertSame(
            [self::FORWARDED_SIGNAL . '@' . HeldAgentSignalTestRouter::COLD_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * An agent already up takes the forwarded frame at once, which is what the case above is
     * worth nothing without: a hold that never ends would look the same from the outside.
     */
    public function testAFrameReceivedFromAnotherNodeForAnAgentAlreadyUpIsDeliveredStraightAway(): void
    {
        $manager = new HeldAgentSignalTestManager();

        $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::UP_AGENT, null, $this->forwardedSignal());

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame(
            [self::FORWARDED_SIGNAL . '@' . HeldAgentSignalTestRouter::UP_AGENT],
            $manager->workerServer->deliveries,
        );
    }

    /**
     * The one hold that waits on a placement verdict keeps waiting when its agent is not
     * starting but already RUNNING here.
     *
     * Nothing is coming for an agent that reported its start long ago, so a release that read the
     * worker link alone would call this a start under way, stop waiting on placement and hold the
     * frame for the life of the process. The address can be missing for an agent running right
     * here: a follower with no placement view yet, a cluster mid-election. A verdict is what
     * ends that wait.
     */
    public function testAnAddresslessHoldKeepsWaitingOnPlacementWhenItsAgentIsAlreadyUpHere(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $manager->workerServer->ensureAgentUp(HeldAgentSignalTestRouter::UP_AGENT, null);
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_UP_PAGE);
        $manager->drainQueue();
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacement);

        $manager->drainQueue();
        $this->assertTrue($manager->heldFrames()[0]->awaitingPlacement);

        $manager->reportNotPlaced(HeldAgentSignalTestRouter::UP_AGENT);

        $this->assertCount(1, $manager->pageErrorFrames());
        $this->assertSame([], $manager->heldFrames());
    }

    /**
     * A frame that already crossed the mesh is marked, and the mark is what keeps the release
     * from asking the placement about it a second time.
     */
    public function testAFrameFromAnotherNodeIsHeldWithTheMarkAndALocalOneWithout(): void
    {
        $manager = new HeldAgentSignalTestManager();

        $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, $this->forwardedSignal());
        $this->assertTrue($manager->heldFrames()[0]->localOnly);

        $local = new HeldAgentSignalTestManager();
        $this->queuePush(HeldAgentSignalTestRouter::COLD_PUSH);
        $local->drainQueue();
        $this->assertFalse($local->heldFrames()[0]->localOnly);
    }

    /**
     * The agent turns up on another node while the forwarded frame waits: the frame is dropped
     * with a line rather than sent on. Sending it on would put a third opinion about the host on
     * the wire, and two nodes that disagree would trade the frame back and forth.
     */
    public function testAFrameFromAnotherNodeIsDroppedRatherThanForwardedOnAgain(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null, $this->forwardedSignal());
        $this->assertCount(1, $manager->heldFrames());

        $this->placeOnAnotherNode(HeldAgentSignalTestRouter::COLD_AGENT);
        new ReflectionMethod(SignalDispatcher::class, 'queueAgentDiscovery')
            ->invoke(SignalDispatcherFixture::owner($manager), HeldAgentSignalTestRouter::COLD_AGENT, true);
        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame([], $manager->workerServer->deliveries);
        $this->assertSame([], $manager->placedReleases, 'The placing door must not be asked about a forwarded frame.');
    }

    /**
     * The control the case above is worth nothing without: a frame this node parked itself IS
     * handed to the placing door when its agent turns up elsewhere, which is what forwards it.
     */
    public function testAFrameThisNodeParkedIsStillForwardedWhenItsAgentTurnsUpElsewhere(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queuePush(HeldAgentSignalTestRouter::COLD_PUSH);
        $manager->drainQueue();
        $manager->placedReleases = [];

        $this->placeOnAnotherNode(HeldAgentSignalTestRouter::COLD_AGENT);
        new ReflectionMethod(SignalDispatcher::class, 'queueAgentDiscovery')
            ->invoke(SignalDispatcherFixture::owner($manager), HeldAgentSignalTestRouter::COLD_AGENT, true);
        $manager->drainQueue();

        $this->assertSame([HeldAgentSignalTestRouter::COLD_AGENT], $manager->placedReleases);
    }

    /**
     * Registers a placement lookup that names no host for one agent.
     *
     * @param string $agentId Agent the lookup reports running nowhere
     */
    private function leaveUnplaced(string $agentId): void
    {
        $context = new ClusterContext();
        $context->registerWorkerPlacement(new class ($agentId) implements WorkerPlacement {
            /**
             * @param string $unplacedAgentId Agent id this lookup answers as unknown
             */
            public function __construct(private readonly string $unplacedAgentId)
            {
            }

            /**
             * @param string $agentType Agent type to look up
             * @param ?string $agentIndex Agent index, or null for a singleton agent
             * @return AgentLocation Unknown for the unplaced agent, here for any other
             */
            public function locate(string $agentType, ?string $agentIndex): AgentLocation
            {
                $agentId = $agentIndex !== null ? "{$agentType}:{$agentIndex}" : $agentType;

                return $agentId === $this->unplacedAgentId
                    ? AgentLocation::unknown()
                    : AgentLocation::here();
            }
        });

        Hilos::$cluster = $context;
    }

    /**
     * Registers a placement lookup that sends one agent off this node.
     *
     * @param string $agentId Agent the lookup reports running elsewhere
     */
    private function placeOnAnotherNode(string $agentId): void
    {
        $context = new ClusterContext();
        $context->registerWorkerPlacement(new class ($agentId, self::OTHER_NODE) implements WorkerPlacement {
            /**
             * @param string $placedAgentId Agent id this lookup sends off the node
             * @param string $nodeId Node it names for that agent
             */
            public function __construct(private readonly string $placedAgentId, private readonly string $nodeId)
            {
            }

            public function locate(string $agentType, ?string $agentIndex): AgentLocation
            {
                $agentId = $agentIndex !== null ? "{$agentType}:{$agentIndex}" : $agentType;

                return $agentId === $this->placedAgentId
                    ? AgentLocation::onNode($this->nodeId)
                    : AgentLocation::here();
            }
        });

        Hilos::$cluster = $context;
    }

    /**
     * @param AgentLocation $location View of the agent whose start needs a leader's order
     */
    private function placeOrderedAgent(AgentLocation $location): void
    {
        $context = new ClusterContext();
        $context->registerWorkerPlacement(new class ($location) implements WorkerPlacement {
            /** @param AgentLocation $location Placement view this fixture reports */
            public function __construct(private readonly AgentLocation $location)
            {
            }

            /**
             * @param string $agentType Agent type to look up
             * @param ?string $agentIndex Agent index, or null for a singleton agent
             * @return AgentLocation Configured view for the ordered agent, here for all others
             */
            public function locate(string $agentType, ?string $agentIndex): AgentLocation
            {
                return $agentType === HeldAgentSignalTestRouter::ORDERED_AGENT
                    ? $this->location
                    : AgentLocation::here();
            }
        });

        Hilos::$cluster = $context;
    }

    /** Reads the ask sent by the isolated placement fixture before simulating its verdict. */
    private function askForVerdict(ClusterPlacement $placement, string $agent): PeerPlacementRequestDTO
    {
        $placement->requirePlacement($agent, null);
        $mesh = new ReflectionProperty($placement, 'mesh')->getValue($placement);
        $frames = array_column($mesh->sent, 1);
        foreach (array_reverse($frames) as $frame) {
            if ($frame instanceof PeerPlacementRequestDTO && $frame->agentType === $agent) {
                return $frame;
            }
        }
        self::fail('The placement fixture did not send an ask');
    }

    /** @return array{ClusterPlacement, SnapshotTestLeadership} Local coordinator and the independent leader knowledge */
    private function recoveringPlacement(HeldAgentSignalTestManager $manager): array
    {
        $this->declareGoneListeners(HeldRecoveryTestHilos::class);
        Hilos::$cluster = new ClusterContext();
        $authority = new SnapshotTestLeadership(self::FIRST_PLACING_LEADER, self::REPLAY_TERM);
        Hilos::$cluster->registerLeadership($authority);
        $placement = new ClusterPlacement(
            self::RECOVERY_SOURCE,
            new FakePlacementMesh([self::RECOVERY_SOURCE => ['slots=10']]),
            new FakePlacementExecutor(),
        );
        Hilos::$cluster->registerPlacement($placement);
        Hilos::$cluster->registerWorkerPlacement($placement);
        Hilos::$cluster->registerPlacementVerdictSink($manager);
        Hilos::$cluster->registerAgentSignalSink($manager);
        $agent = HeldAgentSignalTestRouter::ORDERED_AGENT;
        $order = new PlacementId(self::FIRST_PLACING_LEADER, self::REPLAY_TERM, 1);
        $placement->onPlacementQuery(self::FIRST_PLACING_LEADER, new PeerPlacementQueryDTO('query', self::REPLAY_TERM));
        $placement->onPlaceAgent(self::FIRST_PLACING_LEADER, new PeerPlaceAgentDTO($agent, null, $order));
        $placement->beginLocalPlacementRecovery($agent, $order);
        $this->acceptRecoveryView($placement, self::FIRST_PLACING_LEADER, self::REPLAY_TERM, 1,
            [self::RECOVERY_SOURCE => [new PeerPlacedAgentEntry($agent, null)]],
            [new PlacementRecord($agent, null, self::RECOVERY_SOURCE, PlacementState::Started, placementId: $order)]);

        return [$placement, $authority];
    }

    /**
     * @param array<string, list<PeerPlacedAgentEntry>> $addresses Compact projection published beside the records
     * @param list<PlacementRecord> $records Complete records to accept
     */
    private function acceptRecoveryView(ClusterPlacement $placement, string $leader, int $term, int $revision,
        array $addresses, array $records): void
    {
        $placement->onPlacementView($leader, new PeerPlacementViewDTO($leader, $addresses,
            new PlacementSnapshot($leader, $term, $revision, $records)));
    }

    /**
     * @param ClusterPlacement $placement Recovery coordinator being handed confirmed worker proof
     * @param string $agent Original addressed agent
     * @param string $node Current serving node
     * @param int $revision Snapshot revision and matching assignment sequence
     */
    private function acceptRecoveryAddress(ClusterPlacement $placement, string $agent, string $node, int $revision): void
    {
        $this->acceptRecoveryView($placement, self::FIRST_PLACING_LEADER, self::REPLAY_TERM, $revision,
            [$node => [new PeerPlacedAgentEntry($agent, null)]], [
                new PlacementRecord($agent, null, $node, PlacementState::Started,
                    placementId: new PlacementId(self::FIRST_PLACING_LEADER, self::REPLAY_TERM, $revision), workerStarted: true),
            ]);
    }

    /**
     * @param ReflectionMethod $advance Production release selection and continuation
     * @param SignalDispatcher $dispatcher Original owner of the release slot
     * @param HeldAgentSignalTestManager $manager Original worker owner
     * @param PeerServer $peer Original peer queue owner
     */
    private function advanceToRecoveryAddressYield(ReflectionMethod $advance, SignalDispatcher $dispatcher,
        HeldAgentSignalTestManager $manager, PeerServer $peer): void
    {
        $work = new ReflectionProperty(SignalDispatch::class, 'work');
        for ($step = 0; $step < 20; $step++) {
            $advance->invoke($dispatcher, $manager->workerServer, $peer);
            $cursor = $dispatcher->retainedState()->released;
            if ($cursor === null) {
                continue;
            }
            $executing = new ReflectionGenerator($work->getValue($cursor))->getExecutingGenerator();
            if (new ReflectionGenerator($executing)->getFunction()->getName() === 'walkAddressedDelivery'
                && $executing->key() === 1) {
                return;
            }
        }
        self::fail('The release did not reach its final addressed peer yield');
    }

    /** @return array{PeerServer, PeerLink, Socket} Local addressed queue and its controlled peer socket */
    private function peerRecoveryRoute(HeldAgentSignalTestManager $manager): array
    {
        $peer = new ReflectionClass(PeerServer::class)->newInstanceWithoutConstructor();
        $identity = NodeIdentity::of(self::RECOVERY_SOURCE, 'Source', NodeRole::Master, []);
        new ReflectionProperty(PeerServer::class, 'localIdentity')->setValue($peer, $identity);
        [$link, $reader] = $this->attachRecoveryPeer($peer);
        $manager->registerServer($peer);
        $peer->setClientSocketAttacher(static function (): void {});

        return [$peer, $link, $reader];
    }

    /** @return array{PeerLink, Socket} Current peer slot and its controlled peer socket */
    private function attachRecoveryPeer(PeerServer $peer): array
    {
        $identity = new ReflectionProperty(PeerServer::class, 'localIdentity')->getValue($peer);
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets));
        socket_set_nonblock($sockets[0]);
        socket_set_nonblock($sockets[1]);
        $link = new PeerLink($sockets[0], $peer, $identity, false, new PlainSocketTransport($sockets[0]));
        $link->activate(NodeIdentity::of(self::OTHER_NODE, 'Destination', NodeRole::Master, []));
        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($peer, $link);
        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($peer, $link);
        new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($peer, [self::OTHER_NODE => $link]);
        return [$link, $sockets[1]];
    }

    /**
     * @return SignalDTO One frame shaped the way the peer transport hands one over
     */
    private function forwardedSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(self::FORWARDED_SIGNAL),
            new SignalData([]),
        );
    }

    /**
     * Binds a facade whose one agent declares the loss fact, for the cases that need a listener.
     */
    private function declareGoneListener(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, AgentsGoneTestHilos::class);
    }

    /** @param class-string<Hilos> $facade Project facade whose declarations are under test */
    private function declareGoneListeners(string $facade): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $facade);
    }

    /** @return array<string, SignalDTO> Both loss channels, collected in one pass */
    private function namedGoneFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if (!in_array($name, [HilosSignalConstants::HILOS_AGENTS_GONE, HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE], true)) {
                continue;
            }
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(AgentsGoneSignalData::class, $signal->data->data);
            self::assertArrayNotHasKey($name, $frames);
            $frames[$name] = $signal;
        }

        return $frames;
    }

    /**
     * @param HeldAgentSignalTestManager $manager Real master dispatch with a stand-in worker
     * @param array<string, SignalDTO> $frames Previously observed announcements
     */
    private function deliverGoneFrames(HeldAgentSignalTestManager $manager, array $frames): void
    {
        foreach ($frames as $name => $signal) {
            $recipient = Hilos::appClass()::getAgentSignalRoutes()[$name];
            self::assertIsString($recipient, 'Each route remains scalar');
            $manager->workerServer->seatAgentUnderWay($recipient);
            $manager->reportStarted($recipient);
            Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }
        $manager->drainQueue();
    }

    /**
     * @return list<array{list<string>, string}> Agents and reason of every loss fact queued, in order
     */
    private function goneFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_AGENTS_GONE) {
                continue;
            }
            $payload = $signal->data;
            $this->assertInstanceOf(AgentSignalData::class, $payload);
            $gone = $payload->data;
            $this->assertInstanceOf(AgentsGoneSignalData::class, $gone);
            $frames[] = [$gone->agentIds, $gone->reason];
        }

        return $frames;
    }

    /**
     * @param string $command Command name the router answers with its case's agent
     */
    private function queueCommand(string $command): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName($command),
            new CommandRequestDTO(self::CORRELATION_ID, $command),
        );
    }

    /**
     * @param string $path Path the router answers with its case's agent
     */
    private function queueHttpRequest(string $path): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::HTTP_REQUEST),
            new SignalName(HttpConstants::METHOD_GET . ' ' . $path),
            new HttpRequestDTO(self::CORRELATION_ID, HttpConstants::METHOD_GET, $path, [], null, null),
        );
    }

    /**
     * @param string $name Signal name the router answers with its case's destinations
     */
    /**
     * Queues a connection close the router addresses to the frozen agent.
     */
    private function queueFrozenClose(): void
    {
        $signal = $this->frozenCloseSignal();
        Hilos::$sr->queueSignal(
            $signal->signalSource,
            $signal->signalType,
            $signal->signalName,
            $signal->data,
        );
    }

    /**
     * @return SignalDTO A connection close named apart from the ordinary close, so that case stays as it is
     */
    private function frozenCloseSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
            new SignalName(HeldAgentSignalTestRouter::FROZEN_CLOSE),
            new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
        );
    }

    private function queuePush(string $name): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($name),
            new SignalData([]),
        );
    }

    /**
     * @param string $page Page the subscribe names, which the router answers with its case's agent
     */
    private function queueSubscribe(string $page): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($page),
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $page),
        );
    }
}

/**
 * Daemon manager carrying a worker server that starts agents without a worker process behind them.
 */
final class HeldAgentSignalTestManager extends DaemonManager
{
    /** @var int Index of the worker the loss cases report dead */
    private const int HOST_WORKER = 3;

    /** The stand-in worker server the drain delivers through */
    public HeldAgentSignalTestWorkerServer $workerServer;

    /** @var list<string> Agent types the placing door was asked to reach, in order */
    public array $placedReleases = [];

    /** @var list<string> Agents whose failed local start the delivery door recorded */
    public array $refusedStarts = [];

    /** @var int Placement asks the release repeated while a frame waited on a verdict */
    public int $placementAsks = 0;

    /** @var ?string Not-placed reason an ask answers in place, as the leader's own does; null answers nothing */
    public ?string $leaderVerdict = null;

    /** @var ?Closure(): void Observer after the typed immediate callback returns */
    public ?Closure $afterPlacementVerdict = null;

    /** @var list<int> Exact queue size when the original worker-loss hook runs */
    public array $lossHookQueuedCounts = [];

    public ?RuntimeException $refusalFailure = null;

    public function __construct()
    {
        parent::__construct();

        $this->workerServer = new HeldAgentSignalTestWorkerServer($this->agentManagerDaemon);
        $this->registerServer($this->workerServer);
    }

    /**
     * Runs the private queue drain the daemon loop runs at the end of each iteration.
     */
    public function drainQueue(): void
    {
        new ReflectionClass(DaemonManager::class)->getMethod('dispatchSignals')->invoke($this);
    }

    /**
     * Records that the placing door was asked, which is the door a forwarded frame must not reach.
     *
     * @param SignalDispatch $dispatch Original delivery lifetime
     * @param WorkerServer $workerServer Worker server hosting the agents of this node
     * @param ?AgentSignalMesh $mesh Outbound peer port, or null when no peer server is registered
     * @param AgentAddressedDestination $destination Agent to reach, already placed
     * @param SignalDTO $signal Signal to deliver
     * @return AgentDeliveryOutcome What the delivery answered
     * @throws AgentException When a local agent cannot be reached and the daemon is not shutting down
     * @throws HilosException Whatever the project's agent-daemon factory raises while the local agent starts
     */
    protected function deliverToAgentDestination(
        SignalDispatch $dispatch,
        WorkerServer $workerServer,
        ?AgentSignalMesh $mesh,
        AgentAddressedDestination $destination,
        SignalDTO $signal,
    ): AgentDeliveryOutcome {
        $this->placedReleases[] = $destination->agentType;

        return parent::deliverToAgentDestination($dispatch, $workerServer, $mesh, $destination, $signal);
    }

    /**
     * Counts every ask the release repeats while a frame waits on a verdict, and answers it in place
     * when the case stands on the leader, whose own ask brings its verdict back in the same stack.
     *
     * @param string $agentType Agent type that could not be addressed
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param ?PlacementVerdictSink $immediateVerdict Synchronous answer port for the original delivery
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws CoreInvalidArgumentException When a refusal answering a page or a command cannot be named
     */
    protected function requireOnDemandPlacement(string $agentType, ?string $agentIndex, ?PlacementVerdictSink $immediateVerdict = null): void
    {
        $this->placementAsks++;
        parent::requireOnDemandPlacement($agentType, $agentIndex, $immediateVerdict);
        if ($this->leaderVerdict !== null) {
            ($immediateVerdict ?? $this)->onAgentNotPlaced($agentType, $agentIndex, $this->leaderVerdict);
            $this->afterPlacementVerdict?->__invoke();
        }
    }

    /**
     * Delivers the report a worker sends once the agent's onStart() has returned.
     *
     * @param string $agentType Agent that finished starting
     */
    public function reportStarted(string $agentType): void
    {
        $this->agentManagerDaemon->handleAgentStarted(new WorkerAgentStartedDTO($agentType, $agentType));
    }

    /**
     * Delivers the report a worker sends when the agent's start did not finish.
     *
     * @param string $agentType Agent whose start failed
     */
    public function reportStartFailed(string $agentType, ?PlacementRecovery $recovery = null): void
    {
        $this->agentManagerDaemon->handleAgentStartFailed(
            new WorkerAgentStartFailedDTO($agentType, $agentType, null, 'the state it reads did not arrive'),
            $recovery,
        );
    }

    /**
     * Delivers the report a worker-bound stop makes after the roster has forgotten the agent.
     *
     * @param string $agentType Agent that was stopped while its start was still running
     */
    public function reportStopped(string $agentType): void
    {
        $this->agentManagerDaemon->removeAgent($agentType);
        $this->agentManagerDaemon->reportAgentStopped($agentType);
    }

    /**
     * @param string $agentType Agent to look up
     * @return bool Whether the master's roster still holds a record of the agent
     */
    public function holdsRecordOf(string $agentType): bool
    {
        return $this->agentManagerDaemon->hasAgent($agentType);
    }

    /**
     * Answers the frames held for an agent the leader could not place.
     *
     * @param string $agentType Agent that was not placed
     * @param string $reason Why it was not placed, including the record state
     * @throws CoreInvalidArgumentException When a refusal answering a page or a command cannot be named
     */
    public function reportNotPlaced(string $agentType, string $reason = 'unplaced: no capable node'): void
    {
        $this->onAgentNotPlaced($agentType, null, $reason);
    }

    /**
     * Delivers the report the roster makes when a worker dies with agents still on it.
     *
     * @param string $agentType Agent the dead worker was hosting
     */
    public function reportWorkerLost(string $agentType): void
    {
        $this->reportAgentsLostWithWorker(self::HOST_WORKER, false, [$agentType]);
    }

    /**
     * Rewinds every held frame by the given seconds, so the next drain sees it as that much older.
     *
     * Time passes for the moment the hold began, which is what lets a case say "this much
     * waiting went by" without waiting. A frame waiting on a verdict carries no clock, so all
     * that changes is its age (HIL-1041).
     *
     * @param float $seconds Seconds of waiting to simulate
     */
    public function ageHeldFrames(float $seconds): void
    {
        foreach ($this->holdEntries() as $hold) {
            $parked = $hold->parked;
            if ($parked === null) {
                continue;
            }
            $hold->parked = new ParkedAgentSignal(
                $parked->signal,
                $parked->agentId,
                $parked->parkedAt - $seconds,
                $parked->awaitingPlacement,
                $parked->localOnly,
                $parked->awaitingResume,
                $parked->awaitingPlacementOrder,
                $parked->groupOperation,
            );
        }
    }

    /** @return list<ParkedAgentSignal> Frames still blocked for an agent */
    public function heldFrames(): array
    {
        $parked = [];
        foreach ($this->holdEntries() as $hold) {
            if ($hold->isParked()) {
                $parked[] = $hold->parked;
            }
        }
        return $parked;
    }

    /** @return SignalDispatch Next actual production one-agent cursor, without sending its operation */
    public function beginReleasedCursor(): SignalDispatch
    {
        $owner = new ReflectionClass(DaemonManager::class)->getMethod('agentSignalHolds')->invoke($this);
        $holdId = $owner->firstReleasedId();
        $cursor = new ReflectionMethod(SignalDispatcher::class, 'createReleasedAgentDispatch')->invoke(
            new ReflectionClass(DaemonManager::class)->getMethod('signalDispatcher')->invoke($this),
            $owner->get($holdId), $this->workerServer, null,
        );
        $owner->begin($holdId, $cursor);
        return $cursor;
    }

    /**
     * @param SignalDispatch $cursor Actual one-agent lifetime
     * @param int $steps Allowed work steps
     */
    public function advanceReleasedCursor(SignalDispatch $cursor, int $steps): void
    {
        new ReflectionClass(DaemonManager::class)->getMethod('signalDispatcher')->invoke($this)->runSignalPortion($cursor, $steps);
        $owner = new ReflectionClass(DaemonManager::class)->getMethod('agentSignalHolds')->invoke($this);
        if (!$cursor->hasWork() && $cursor->heldId !== null && $owner->get($cursor->heldId)?->dispatch === $cursor) {
            $owner->settle($cursor->heldId);
        }
    }

    /**
     * @return list<WebSocketSignalData> Subscription errors queued for a browser, in order
     */
    public function pageErrorFrames(): array
    {
        $router = Hilos::$sr;

        return $router instanceof HeldAgentSignalTestRouter ? $router->pageErrorFrames : [];
    }

    /**
     * @param int $workerIndex Lost worker
     * @param bool $isMonopolistic Its worker kind
     * @param list<string> $agentIds Original loss cohort
     */
    protected function onAgentsLostWithWorker(int $workerIndex, bool $isMonopolistic, array $agentIds): void
    {
        $this->lossHookQueuedCounts[] = Hilos::$sr->getQueuedSignalCount();
    }

    /** @param SignalDTO $signal Page whose actual refusal is observed */
    protected function answerUnreachableSubscription(SignalDTO $signal): void
    {
        parent::answerUnreachableSubscription($signal);
        if ($this->refusalFailure !== null) {
            throw $this->refusalFailure;
        }
    }

    /**
     * @param string $agentId Agent whose refused start is observed
     * @param string $agentLabel Agent as the journal names it
     * @param string $signalType Type of the signal that asked for it
     * @param string $signalName Name of the signal that asked for it
     * @param HilosException $failure Failure the delivery door caught
     */
    protected function answerRefusedAgentStart(
        string $agentId,
        string $agentLabel,
        string $signalType,
        string $signalName,
        HilosException $failure,
    ): void {
        $this->refusedStarts[] = $agentId;
        parent::answerRefusedAgentStart($agentId, $agentLabel, $signalType, $signalName, $failure);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new HeldAgentSignalTestRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new HeldAgentSignalTestAgentManagerDaemon();
    }

    /** @return array<int, AgentSignalHold> Actual production owner's pending entries */
    private function holdEntries(): array
    {
        $owner = new ReflectionClass(DaemonManager::class)->getMethod('agentSignalHolds')->invoke($this);
        return new ReflectionProperty(AgentSignalHolds::class, 'entries')->getValue($owner);
    }
}

/**
 * Router that answers each test signal name with the agents its case is about, and keeps the
 * subscription errors queued for a browser so a case can read the frame the drain then consumes.
 */
final class HeldAgentSignalTestRouter extends SignalRouter
{
    public const string COLD_AGENT = 'held_cold_agent';

    public const string UP_AGENT = 'held_up_agent';

    public const string FROZEN_AGENT = 'held_frozen_agent';

    public const string ORDERED_AGENT = 'held_ordered_agent';

    public const string COLD_PUSH = 'cold_push';

    public const string COLD_FOLLOW_UP = 'cold_follow_up';

    public const string SHARED_PUSH = 'shared_push';

    public const string UP_PUSH = 'up_push';

    public const string FROZEN_PUSH = 'frozen_push';

    public const string FROZEN_CLOSE = 'frozen_close';

    public const string ORDERED_PUSH = 'ordered_push';

    public const string COLD_COMMAND = 'cold:command';

    public const string COLD_HTTP_PATH = '/_test/cold';

    public const string COLD_PAGE = 'cold_room';

    public const string ORDERED_PAGE = 'ordered_room';

    public const string UNPLACED_PAGE = 'unplaced_room';

    public const string UNPLACED_UP_PAGE = 'unplaced_up_room';

    /** @return class-string<Hilos> Facade bound by the case, including its actual scalar loss routes */
    protected function hilosClass(): string
    {
        return Hilos::appClass();
    }

    /** @var list<WebSocketSignalData> Subscription errors queued for a browser, in order */
    public array $pageErrorFrames = [];

    /** @var list<string> Actual queued command replies, without retaining their payloads */
    public array $returnedCorrelations = [];

    /**
     * @param SignalSourceInterface $signalSource Source of the signal
     * @param SignalTypeInterface $signalType Type of the signal
     * @param SignalNameInterface $signalName Name of the signal
     * @param SignalDataInterface $signalData Payload of the signal
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        if ($signalName->getName() === SignalConstants::SUBSCRIPTION_PAGE_ERROR && $signalData instanceof WebSocketSignalData) {
            $this->pageErrorFrames[] = $signalData;
        }
        if ($signalData instanceof CommandReplyDTO) {
            $this->returnedCorrelations[] = $signalData->correlationId;
        }

        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
    }

    /**
     * @param SignalDTO $signal Signal being routed
     * @return list<Destination> The destinations this signal's case needs, or none
     */
    protected function additionalDestinations(SignalDTO $signal): array
    {
        return match ($signal->signalName->getName()) {
            self::COLD_PUSH, self::COLD_FOLLOW_UP, self::COLD_PAGE,
            self::COLD_COMMAND, HttpConstants::METHOD_GET . ' ' . self::COLD_HTTP_PATH
                => [new AgentDestination(self::COLD_AGENT)],
            self::SHARED_PUSH => [new AgentDestination(self::UP_AGENT), new AgentDestination(self::COLD_AGENT)],
            self::UP_PUSH => [new AgentDestination(self::UP_AGENT)],
            self::FROZEN_PUSH, self::FROZEN_CLOSE => [new AgentDestination(self::FROZEN_AGENT)],
            self::ORDERED_PUSH, self::ORDERED_PAGE => [new AgentDestination(self::ORDERED_AGENT)],
            self::UNPLACED_PAGE => [new UnknownAgentDestination(self::COLD_AGENT)],
            self::UNPLACED_UP_PAGE => [new UnknownAgentDestination(self::UP_AGENT)],
            default => [],
        };
    }
}

/**
 * Agent manager for which one agent is up from the start and the rest are up once reported.
 */
final class HeldAgentSignalTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentId Agent the drain asks about
     * @return bool Whether the agent is up: the up one always, the others once their start is reported
     */
    public function isAgentStarted(string $agentId): bool
    {
        return $agentId === HeldAgentSignalTestRouter::UP_AGENT || parent::isAgentStarted($agentId);
    }

    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; the stand-in worker server registers its own
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server whose start registers a record linked to a worker, as a start under way does,
 * except for the frozen and ordered agents, whose starts the freeze or placement gate refuses quietly.
 */
final class HeldAgentSignalTestWorkerServer extends WorkerServer
{
    /** @var list<string> Agent types a start was asked for, in order */
    public array $startedAgentTypes = [];

    /** @var list<string> Deliveries as `<signal name>@<agent type>`, in order */
    public array $deliveries = [];

    /** @var list<WebSocketPageUpdateSubscriptionSignalDTO> Updates queued to the worker, awaiting its completion */
    public array $updates = [];

    /** @var list<string> Agent types the freeze stopped and the lift has not asked back yet */
    public array $awaitingResume = [];

    /** Roster the stand-in start registers its record in */
    private AgentManagerDaemon $roster;

    public function __construct(AgentManagerDaemon $roster)
    {
        $this->roster = $roster;
    }

    /**
     * @param string $agentType Agent type the frame is addressed to
     * @param ?string $agentIndex Agent index for a pooled agent, or null
     * @param ?AgentStartSink $immediateStartSink Synchronous notification port for this call
     */
    public function ensureAgentUp(string $agentType, ?string $agentIndex, ?AgentStartSink $immediateStartSink = null): void
    {
        $this->startedAgentTypes[] = $agentType;
        if ($agentType === HeldAgentSignalTestRouter::FROZEN_AGENT || $agentType === HeldAgentSignalTestRouter::ORDERED_AGENT) {
            return;
        }

        $this->seatAgentUnderWay($agentType);
    }

    /**
     * @param string $agentType Agent type whose start needs the leader's order in this fixture
     * @return bool Whether this is the fixture's ordered policy agent
     */
    public function needsPlacementOrder(string $agentType): bool
    {
        return $agentType === HeldAgentSignalTestRouter::ORDERED_AGENT;
    }

    /**
     * Seats an agent the way a start under way does, including one the freeze would refuse to start.
     *
     * @param string $agentType Agent type to seat
     */
    public function seatAgentUnderWay(string $agentType): void
    {
        $this->roster->addAgent($agentType, new HeldAgentSignalTestAgentDaemon(), 1, false);
        new ReflectionProperty(WorkerServer::class, 'agentHoldWake')->getValue($this)?->__invoke($agentType);
    }

    /** Completes the fixture's resume walk without waiting for an agent-start report. */
    public function finishResume(): void
    {
        $this->awaitingResume = [];
        new ReflectionProperty(WorkerServer::class, 'agentHoldWake')->getValue($this)?->__invoke(null);
    }

    /**
     * @param string $agentType Agent type to ask about
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return bool Whether the fixture says that agent still awaits the lift
     */
    public function awaitsProtectedModeResume(string $agentType, ?string $agentIndex): bool
    {
        return in_array($agentType, $this->awaitingResume, true);
    }

    /**
     * @param string $agentType Agent type the signal was routed to
     * @param ?string $agentIndex Agent index for a pooled agent, or null
     * @param DaemonAgentMessageDTO $messageDto Signal wrapped for the worker
     * @param ?AgentStartSink $immediateStartSink Synchronous notification port for this call
     */
    public function sendSignalToAgent(
        string $agentType,
        ?string $agentIndex,
        DaemonAgentMessageDTO $messageDto,
        ?AgentStartSink $immediateStartSink = null,
    ): void
    {
        $this->deliveries[] = $messageDto->signal->signalName->getName() . '@' . $agentType;
        if ($messageDto->signal->data instanceof WebSocketPageUpdateSubscriptionSignalDTO) {
            $this->updates[] = $messageDto->signal->data;
        }
    }

    protected function onStart(): void
    {
    }
}

/** A real socket delivery lifetime driven through the frame parser without a listener. */
final class HeldAgentSignalTestWebSocket extends WebSocketClient
{
    /** @var list<array<string, mixed>> Recovery controls admitted to the browser transport */
    public array $pageRecoveryFrames = [];

    public function __construct(Socket $socket, string $acceptKey)
    {
        parent::__construct($socket);
        $this->handshakeCompleted = true;
        $this->acceptKey = $acceptKey;
    }

    /** @param array<string, int> $params Original browser update parameters */
    public function update(string $page, array $params): void
    {
        $this->onFrame(json_encode(['type' => 'page_update_subscription', 'page' => $page, 'params' => $params], JSON_THROW_ON_ERROR));
    }

    /** @param DeliveryFrame $frame Browser frame arriving at the socket queue */
    public function sendDeliveryFrame(DeliveryFrame $frame): void
    {
        parent::sendDeliveryFrame($frame);
        if ($frame->getType() === SignalConstants::PAGE_RESUBSCRIBE) {
            $this->pageRecoveryFrames[] = json_decode($frame->json, true, flags: JSON_THROW_ON_ERROR);
        }
    }

    protected function onHandshake(array $headers, string $acceptKey, array $cookies, ?string $clientIp, RequestQueryParams $queryParams): void
    {
    }

    protected function onClose(): void
    {
    }
}

final class HeldAgentSignalTestWebSocketServer extends WebSocketServer
{
    public function attach(WebSocketClient $client): void
    {
        $this->addClient($client);
    }

    protected function onStart(): void
    {
    }

    protected function onCreateClient($socket): WebSocketClientInterface
    {
        return new HeldAgentSignalTestWebSocket($socket, 'browser');
    }
}

/**
 * Agent daemon that stands linked to a worker without a worker client behind it.
 */
final class HeldAgentSignalTestAgentDaemon extends AbstractAgentDaemon
{
    public function hasWorkerClient(): bool
    {
        return true;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * @param AgentMessageDTOInterface $message Message that would go to a user; unused here
     */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
    }
}

/**
 * Facade of a fixture project whose one agent declares the loss fact (HIL-1044).
 */
abstract class AgentsGoneTestHilos extends Hilos
{
    public const array AGENTS = [
        AgentsGoneTestListener::TYPE => [AgentRegistryKey::WORKER => AgentsGoneTestListener::class],
    ];
}

/**
 * The agent answering somebody on other agents' behalf, reduced to its declaration.
 */
final class AgentsGoneTestListener extends AbstractAgent
{
    public const string TYPE = 'agents_gone_listener';

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_AGENTS_GONE => AgentsGoneSignalData::class,
    ];

    public function onStop(): void
    {
    }
}

/** Independent OAuth receiver; it must never claim the sessions channel. */
final class OAuthAgentsGoneTestListener extends AbstractAgent
{
    public const string TYPE = 'oauth_agents_gone_listener';

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE => AgentsGoneSignalData::class,
    ];

    public function onStop(): void
    {
    }
}

/** Both scalar singleton declarations, with different receiving classes. */
abstract class AgentsGoneBothTestHilos extends Hilos
{
    public const array AGENTS = [
        AgentsGoneTestListener::TYPE => [AgentRegistryKey::WORKER => AgentsGoneTestListener::class],
        OAuthAgentsGoneTestListener::TYPE => [AgentRegistryKey::WORKER => OAuthAgentsGoneTestListener::class],
    ];
}

/** OAuth may be enabled without a sessions loss listener. */
abstract class OAuthAgentsGoneTestHilos extends Hilos
{
    public const array AGENTS = [
        OAuthAgentsGoneTestListener::TYPE => [AgentRegistryKey::WORKER => OAuthAgentsGoneTestListener::class],
    ];
}

/** No loss route is declared. */
abstract class NoAgentsGoneTestHilos extends Hilos
{
    public const array AGENTS = [];
}

abstract class HeldRecoveryTestHilos extends Hilos
{
    public const array AGENTS = [
        HeldAgentSignalTestRouter::ORDERED_AGENT => [
            AgentRegistryKey::SCOPE => AgentScope::CLUSTER,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
    ];
}

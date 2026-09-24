<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\AgentSignalMesh;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\DTO\AgentsGoneSignalData;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Daemon\AgentDeliveryOutcome;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Exception\InvalidArgumentException as CoreInvalidArgumentException;
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
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

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
     * A start the freeze or a placement gate refuses leaves no start under way, so there is nothing
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
        $manager->drainQueue();

        $this->assertSame([], $manager->heldFrames());
        $this->assertSame([HeldAgentSignalTestRouter::COLD_AGENT], $manager->placedReleases);
    }

    /**
     * A not-placed verdict answers the waiting page at once, in the words a dropped subscribe
     * always got. There is no clock left to stand in for that answer.
     */
    public function testANotPlacedVerdictAnswersTheWaitingFrameAtOnce(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);
        $manager->drainQueue();

        $manager->reportNotPlaced(HeldAgentSignalTestRouter::COLD_AGENT, 'failed: no worker');

        $frames = $manager->pageErrorFrames();
        $this->assertCount(1, $frames);
        $error = $frames[0]->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame('node_unreachable', $error->errorCode);
        $this->assertSame([], $manager->heldFrames());
    }

    /**
     * While the frame waits on a verdict, every release pass asks for a placement again. The
     * five-second cap already lives inside the ask, so the master repeating the call is what a
     * missing leader is waited out by (HIL-1041).
     */
    public function testAWaitingFrameAsksForPlacementAgainEachReleasePass(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);
        $manager->drainQueue();
        $asksAfterPark = $manager->placementAsks;

        $manager->drainQueue();
        $manager->drainQueue();

        $this->assertSame($asksAfterPark + 2, $manager->placementAsks);
        $this->assertCount(1, $manager->heldFrames());
        $this->assertSame([], $manager->pageErrorFrames());
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
     * @param string $name Signal name the router answers with its case's destinations
     */
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

    /** @var int Placement asks the release repeated while a frame waited on a verdict */
    public int $placementAsks = 0;

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
     * @param WorkerServer $workerServer Worker server hosting the agents of this node
     * @param ?AgentSignalMesh $mesh Outbound peer port, or null when no peer server is registered
     * @param AgentAddressedDestination $destination Agent to reach, already placed
     * @param SignalDTO $signal Signal to deliver
     * @return AgentDeliveryOutcome What the delivery answered
     * @throws AgentException When a local agent cannot be reached and the daemon is not shutting down
     * @throws HilosException Whatever the project's agent-daemon factory raises while the local agent starts
     */
    protected function deliverToAgentDestination(
        WorkerServer $workerServer,
        ?AgentSignalMesh $mesh,
        AgentAddressedDestination $destination,
        SignalDTO $signal,
    ): AgentDeliveryOutcome {
        $this->placedReleases[] = $destination->agentType;

        return parent::deliverToAgentDestination($workerServer, $mesh, $destination, $signal);
    }

    /**
     * Counts every ask the release repeats while a frame waits on a verdict.
     *
     * @param string $agentType Agent type that could not be addressed
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    protected function requireOnDemandPlacement(string $agentType, ?string $agentIndex): void
    {
        $this->placementAsks++;
        parent::requireOnDemandPlacement($agentType, $agentIndex);
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
    public function reportStartFailed(string $agentType): void
    {
        $this->agentManagerDaemon->handleAgentStartFailed(
            new WorkerAgentStartFailedDTO($agentType, $agentType, null, 'the state it reads did not arrive'),
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
        $held = new ReflectionClass(DaemonManager::class)->getProperty('parkedAgentSignals');
        $held->setValue($this, array_map(
            static fn(ParkedAgentSignal $parked): ParkedAgentSignal => new ParkedAgentSignal(
                $parked->signal,
                $parked->agentId,
                $parked->parkedAt - $seconds,
                $parked->awaitingPlacement,
                $parked->localOnly,
            ),
            $held->getValue($this),
        ));
    }

    /**
     * @return list<ParkedAgentSignal> Frames the master holds for an agent right now
     */
    public function heldFrames(): array
    {
        return new ReflectionClass(DaemonManager::class)->getProperty('parkedAgentSignals')->getValue($this);
    }

    /**
     * @return list<WebSocketSignalData> Subscription errors queued for a browser, in order
     */
    public function pageErrorFrames(): array
    {
        $router = Hilos::$sr;

        return $router instanceof HeldAgentSignalTestRouter ? $router->pageErrorFrames : [];
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new HeldAgentSignalTestRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new HeldAgentSignalTestAgentManagerDaemon();
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

    public const string COLD_PUSH = 'cold_push';

    public const string COLD_FOLLOW_UP = 'cold_follow_up';

    public const string SHARED_PUSH = 'shared_push';

    public const string UP_PUSH = 'up_push';

    public const string FROZEN_PUSH = 'frozen_push';

    public const string COLD_COMMAND = 'cold:command';

    public const string COLD_PAGE = 'cold_room';

    public const string UNPLACED_PAGE = 'unplaced_room';

    public const string UNPLACED_UP_PAGE = 'unplaced_up_room';

    /** @var list<WebSocketSignalData> Subscription errors queued for a browser, in order */
    public array $pageErrorFrames = [];

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
            self::COLD_COMMAND => [new AgentDestination(self::COLD_AGENT)],
            self::SHARED_PUSH => [new AgentDestination(self::UP_AGENT), new AgentDestination(self::COLD_AGENT)],
            self::UP_PUSH => [new AgentDestination(self::UP_AGENT)],
            self::FROZEN_PUSH => [new AgentDestination(self::FROZEN_AGENT)],
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
 * except for the frozen agent, whose start it refuses quietly the way the freeze does.
 */
final class HeldAgentSignalTestWorkerServer extends WorkerServer
{
    /** @var list<string> Agent types a start was asked for, in order */
    public array $startedAgentTypes = [];

    /** @var list<string> Deliveries as `<signal name>@<agent type>`, in order */
    public array $deliveries = [];

    /** Roster the stand-in start registers its record in */
    private AgentManagerDaemon $roster;

    public function __construct(AgentManagerDaemon $roster)
    {
        $this->roster = $roster;
    }

    /**
     * @param string $agentType Agent type the frame is addressed to
     * @param ?string $agentIndex Agent index for a pooled agent, or null
     */
    public function ensureAgentUp(string $agentType, ?string $agentIndex): void
    {
        $this->startedAgentTypes[] = $agentType;
        if ($agentType === HeldAgentSignalTestRouter::FROZEN_AGENT) {
            return;
        }

        $this->roster->addAgent($agentType, new HeldAgentSignalTestAgentDaemon(), 1, false);
    }

    /**
     * @param string $agentType Agent type the signal was routed to
     * @param ?string $agentIndex Agent index for a pooled agent, or null
     * @param DaemonAgentMessageDTO $messageDto Signal wrapped for the worker
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->deliveries[] = $messageDto->signal->signalName->getName() . '@' . $agentType;
    }

    protected function onStart(): void
    {
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

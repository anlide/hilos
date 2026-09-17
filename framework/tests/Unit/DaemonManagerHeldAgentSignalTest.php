<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\Destination;
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
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A frame addressed to an agent that is not up yet waits for it in the master (HIL-629).
 *
 * It used to be written to the worker right behind the start, and a start that failed inside the
 * worker took it along - or, for an agent no node was known to host, it was dropped on the spot.
 * Now the master holds it: for the start report of an agent coming up here, or for an address
 * that does not exist yet. It goes to that one agent once the agent is up, and to the refusal it
 * is owed once the start's own ceiling plus a second has passed.
 *
 * The drain is driven the way {@see DaemonManagerAgentStartRefusedTest} drives it; Reflection
 * reaches the private members because the code-style rule grants tests that exception.
 */
final class DaemonManagerHeldAgentSignalTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-629';

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
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
     * A page whose agent never comes up gets the answer a dropped subscribe always got, only later,
     * and the frame is not held a second time.
     */
    public function testAFrameHeldPastItsDeadlineIsAnsweredAsTheDropWasAndNotHeldAgain(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::COLD_PAGE);
        $manager->drainQueue();
        $this->assertSame([], $manager->pageErrorFrames());

        $manager->expireHeldFrames();
        $manager->drainQueue();

        $frames = $manager->pageErrorFrames();
        $this->assertCount(1, $frames);
        $error = $frames[0]->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame('node_unreachable', $error->errorCode);
        $this->assertSame([], $manager->heldFrames());

        $manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $manager->drainQueue();
        $this->assertSame([], $manager->workerServer->deliveries);
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
     * An agent no node is known to host is waited for too, instead of answered at once.
     */
    public function testAFrameForAnAgentWithNoAddressIsHeldAndAnsweredOnlyWhenTheWaitEnds(): void
    {
        $manager = new HeldAgentSignalTestManager();
        $this->queueSubscribe(HeldAgentSignalTestRouter::UNPLACED_PAGE);

        $manager->drainQueue();
        $this->assertSame([], $manager->pageErrorFrames());
        $this->assertCount(1, $manager->heldFrames());

        $manager->expireHeldFrames();
        $manager->drainQueue();

        $this->assertCount(1, $manager->pageErrorFrames());
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
    /** The stand-in worker server the drain delivers through */
    public HeldAgentSignalTestWorkerServer $workerServer;

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
     * @param string $agentType Agent to look up
     * @return bool Whether the master's roster still holds a record of the agent
     */
    public function holdsRecordOf(string $agentType): bool
    {
        return $this->agentManagerDaemon->hasAgent($agentType);
    }

    /**
     * Moves every frame held for an agent past its deadline, so the next drain answers it.
     */
    public function expireHeldFrames(): void
    {
        $held = new ReflectionClass(DaemonManager::class)->getProperty('parkedAgentSignals');
        $held->setValue($this, array_map(
            static fn(ParkedAgentSignal $parked): ParkedAgentSignal => new ParkedAgentSignal($parked->signal, $parked->agentId, 0.0),
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

    public const string COLD_PAGE = 'cold_room';

    public const string UNPLACED_PAGE = 'unplaced_room';

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
            self::COLD_PUSH, self::COLD_FOLLOW_UP, self::COLD_PAGE => [new AgentDestination(self::COLD_AGENT)],
            self::SHARED_PUSH => [new AgentDestination(self::UP_AGENT), new AgentDestination(self::COLD_AGENT)],
            self::UP_PUSH => [new AgentDestination(self::UP_AGENT)],
            self::FROZEN_PUSH => [new AgentDestination(self::FROZEN_AGENT)],
            self::UNPLACED_PAGE => [new UnknownAgentDestination(self::COLD_AGENT)],
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

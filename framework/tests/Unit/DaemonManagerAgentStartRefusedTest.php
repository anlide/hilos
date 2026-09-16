<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentNotFoundException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\Destination;
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
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * An agent of this node that does not come up costs that agent, not the node (HIL-999).
 *
 * Until this leaf a start the worker server refused - no free worker, most often - escaped the
 * master's delivery walk and ended the loop: every agent and every connection of the node went
 * with the one agent that did not fit. Now the delivery writes the refusal down, hands the
 * project a card, and the walk answers whoever is waiting in the shape their own entry already
 * knows: a page gets its subscription error, an operator gets a reply, a push gets nothing.
 *
 * The drain is driven the way {@see DaemonManagerCommandRefusalTest} drives it; Reflection
 * reaches the private members because the code-style rule grants tests that exception.
 */
final class DaemonManagerAgentStartRefusedTest extends TestCase
{
    private const string CORRELATION_ID = 'corr-999';

    private const string ACCEPT_KEY = 'ak-999';

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * The whole leaf in one case: the drain returns instead of throwing into the loop.
     */
    public function testARefusedStartDoesNotEscapeTheDrain(): void
    {
        $manager = new AgentStartRefusedTestManager();
        $this->queuePush(AgentStartRefusedTestRouter::REFUSED_PUSH);

        $manager->drainQueue();

        $this->assertSame([], $manager->deliveredTo());
    }

    public function testAPageWaitingOnTheRefusedAgentIsAnsweredWithTheSubscriptionError(): void
    {
        $manager = new AgentStartRefusedTestManager();
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(AgentStartRefusedTestRouter::REFUSED_PAGE),
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, AgentStartRefusedTestRouter::REFUSED_PAGE),
        );

        $manager->drainQueue();

        $frames = $manager->pageErrorFrames();
        $this->assertCount(1, $frames);
        $this->assertSame(self::ACCEPT_KEY, $frames[0]->targetAcceptKey);
        $error = $frames[0]->data;
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        $this->assertSame(AgentStartRefusedTestRouter::REFUSED_PAGE, $error->page);
        $this->assertSame(HttpConstants::HTTP_SERVICE_UNAVAILABLE, $error->httpCode);
        $this->assertSame('agent_unavailable', $error->errorCode);
        $this->assertSame('This page is temporarily unavailable. Please try again.', $error->message);
    }

    public function testAnOperatorWaitingOnTheRefusedAgentIsAnsweredByName(): void
    {
        $manager = new AgentStartRefusedTestManager();
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(AgentStartRefusedTestRouter::REFUSED_COMMAND),
            new CommandRequestDTO(self::CORRELATION_ID, AgentStartRefusedTestRouter::REFUSED_COMMAND),
        );

        $manager->drainQueue();

        $this->assertSame(
            'The agent that answers protected-mode:open could not be started on this node',
            $manager->refusalMessage(),
        );
    }

    /**
     * Nobody waits on a push, so an error invented for it would put a failure on a screen that
     * never asked for anything.
     */
    public function testAPushToTheRefusedAgentIsAnsweredWithNothing(): void
    {
        $manager = new AgentStartRefusedTestManager();
        $this->queuePush(AgentStartRefusedTestRouter::REFUSED_PUSH);

        $manager->drainQueue();

        $this->assertSame([], $manager->pageErrorFrames());
        $this->assertNull($manager->refusalMessage());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testTheProjectIsHandedOneCardAddressedByTheAgentId(): void
    {
        $manager = new AgentStartRefusedTestManager();
        $this->queuePush(AgentStartRefusedTestRouter::REFUSED_PUSH);

        $manager->drainQueue();

        $this->assertCount(1, $manager->contained);
        $this->assertSame(MasterFailureUnit::AGENT_START, $manager->contained[0]->unit);
        $this->assertSame(AgentStartRefusedTestRouter::REFUSED_AGENT, $manager->contained[0]->address);
        $this->assertInstanceOf(NoSuitableWorkerException::class, $manager->contained[0]->failure);
    }

    /**
     * One agent that did not come up says nothing about the other destinations of its signal.
     */
    public function testTheOtherDestinationOfTheSameSignalIsStillDelivered(): void
    {
        $manager = new AgentStartRefusedTestManager();
        $this->queuePush(AgentStartRefusedTestRouter::SHARED_PUSH);

        $manager->drainQueue();

        $this->assertSame([AgentStartRefusedTestRouter::HEALTHY_AGENT], $manager->deliveredTo());
    }

    /**
     * The lookup that follows a start raises about the same agent that did not come up; holding
     * back only the worker pick would leave the node dying on the neighbouring line.
     */
    public function testAnAgentLostAfterItsStartIsContainedTheSameWay(): void
    {
        $manager = new AgentStartRefusedTestManager();
        $this->queuePush(AgentStartRefusedTestRouter::LOST_PUSH);

        $manager->drainQueue();

        $this->assertCount(1, $manager->contained);
        $this->assertSame(AgentStartRefusedTestRouter::LOST_AGENT, $manager->contained[0]->address);
        $this->assertInstanceOf(AgentNotFoundException::class, $manager->contained[0]->failure);
    }

    /**
     * The project's singleton hook starts agents directly, so a failure inside it is contained
     * whole - and the ensure-once is still marked done, or every tick would run the hook again.
     */
    public function testAFailingSingletonHookLeavesTheNodeStandingAndTheStartMarkedDone(): void
    {
        $manager = new AgentStartRefusedTestManager();
        $manager->workerServer->singletonHookFails = true;
        $reflection = new ReflectionClass(DaemonManager::class);
        $reflection->getProperty('workersReady')->setValue($manager, true);

        $reflection->getMethod('ensureSingletonsStarted')->invoke($manager);

        $this->assertTrue($reflection->getProperty('singletonsStarted')->getValue($manager));
        $this->assertCount(1, $manager->contained);
        $this->assertSame(MasterFailureUnit::AGENT_START, $manager->contained[0]->unit);
        $this->assertSame('cluster singletons', $manager->contained[0]->address);
    }

    /**
     * Queues one agent push, the shape the non-page cases share apart from its name.
     *
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
}

/**
 * Daemon manager carrying a worker server that refuses the agents this test names, a command
 * server the operator's reply is written to, and a hook that keeps the cards it is handed.
 */
final class AgentStartRefusedTestManager extends DaemonManager
{
    /** @var list<ContainedFailure> Cards the master handed to the project, in order */
    public array $contained = [];

    /** The stand-in worker server the drain delivers through */
    public AgentStartRefusedTestWorkerServer $workerServer;

    /** The stand-in command server the refusals are written to */
    private AgentStartRefusedTestCommandServer $commandServer;

    public function __construct()
    {
        parent::__construct();

        $this->workerServer = new AgentStartRefusedTestWorkerServer();
        $this->registerServer($this->workerServer);

        $this->commandServer = new AgentStartRefusedTestCommandServer();
        $this->registerServer($this->commandServer);
    }

    /**
     * Runs the private queue drain the daemon loop runs at the end of each iteration.
     */
    public function drainQueue(): void
    {
        new ReflectionClass(DaemonManager::class)->getMethod('dispatchSignals')->invoke($this);
    }

    /**
     * @return list<string> Agent types the drain delivered to, in order
     */
    public function deliveredTo(): array
    {
        return $this->workerServer->deliveredAgentTypes;
    }

    /**
     * @return list<WebSocketSignalData> Subscription errors queued for a browser, in order
     */
    public function pageErrorFrames(): array
    {
        $router = Hilos::$sr;

        return $router instanceof AgentStartRefusedTestRouter ? $router->pageErrorFrames : [];
    }

    /**
     * Reads the sentence an operator would see, out of the last reply's message field.
     *
     * @return ?string Refusal message, or null when no reply carried one
     */
    public function refusalMessage(): ?string
    {
        $replies = $this->commandServer->replies;
        $payload = $replies === [] ? [] : $replies[count($replies) - 1]->payload;
        $message = $payload[CommandConstants::FIELD_MESSAGE] ?? null;

        return is_string($message) ? $message : null;
    }

    protected function onContainedFailure(ContainedFailure $failure): void
    {
        $this->contained[] = $failure;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new AgentStartRefusedTestRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new AgentStartRefusedTestAgentManagerDaemon();
    }
}

/**
 * Router that answers each test signal name with the agents its case is about, and keeps the
 * subscription errors queued for a browser so a case can read the frame the drain then consumes.
 */
final class AgentStartRefusedTestRouter extends SignalRouter
{
    public const string REFUSED_AGENT = 'start_refused_test_agent';

    public const string LOST_AGENT = 'start_refused_lost_agent';

    public const string HEALTHY_AGENT = 'start_refused_healthy_agent';

    public const string REFUSED_PAGE = 'refused_room';

    public const string REFUSED_COMMAND = 'protected-mode:open';

    public const string REFUSED_PUSH = 'refused_push';

    public const string LOST_PUSH = 'lost_push';

    public const string SHARED_PUSH = 'shared_push';

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
            self::REFUSED_PAGE, self::REFUSED_COMMAND, self::REFUSED_PUSH => [new AgentDestination(self::REFUSED_AGENT)],
            self::LOST_PUSH => [new AgentDestination(self::LOST_AGENT)],
            self::SHARED_PUSH => [new AgentDestination(self::REFUSED_AGENT), new AgentDestination(self::HEALTHY_AGENT)],
            default => [],
        };
    }
}

final class AgentStartRefusedTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; the stand-in worker server starts nothing
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A command server that records the replies instead of writing to a socket.
 */
final class AgentStartRefusedTestCommandServer extends CommandServer
{
    /** @var list<CommandReplyDTO> Replies the drain delivered, in order */
    public array $replies = [];

    public function __construct()
    {
    }

    /**
     * @param string $correlationId Correlation id of the originating request
     * @param CommandReplyDTO $reply Reply the drain wrote back
     */
    public function deliver(string $correlationId, CommandReplyDTO $reply): void
    {
        $this->replies[] = $reply;
    }

    protected function onStart(): void
    {
    }
}

/**
 * A worker server that refuses the two agents the cases name - one for want of a worker, one
 * lost after its start - and records every handoff it does take.
 */
final class AgentStartRefusedTestWorkerServer extends WorkerServer
{
    /** @var list<string> Agent types the drain handed a signal to, in order */
    public array $deliveredAgentTypes = [];

    /** @var bool Whether the project's singleton hook fails the way an unguarded agent start would */
    public bool $singletonHookFails = false;

    public function __construct()
    {
    }

    /**
     * @param string $agentType Agent type the signal was routed to
     * @param ?string $agentIndex Agent index for a pooled agent, or null
     * @param DaemonAgentMessageDTO $messageDto Signal wrapped for the worker
     * @throws NoSuitableWorkerException For the agent no worker is left for
     * @throws AgentNotFoundException For the agent gone after its start
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        if ($agentType === AgentStartRefusedTestRouter::REFUSED_AGENT) {
            throw new NoSuitableWorkerException(WorkerConstants::TYPE_MONOPOLISTIC, true);
        }

        if ($agentType === AgentStartRefusedTestRouter::LOST_AGENT) {
            throw new AgentNotFoundException($messageDto->agentId);
        }

        $this->deliveredAgentTypes[] = $agentType;
    }

    /**
     * @throws RuntimeException When the case asked the hook to fail
     */
    public function onBecameSingletonHost(): void
    {
        if ($this->singletonHookFails) {
            throw new RuntimeException('a bot of the project found no free worker');
        }
    }

    protected function onStart(): void
    {
    }
}

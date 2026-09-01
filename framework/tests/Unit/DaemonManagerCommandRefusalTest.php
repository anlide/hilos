<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\Destination\UnknownAgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What the daemon answers an operator whose command reaches nobody (HIL-730).
 *
 * Three ways a command dies inside the master, and until this ticket all three ended in a log
 * line the operator cannot see: no agent owns the name, nothing placed the agent that does, or
 * the node holding it has no live link. The terminal waited out its five-second budget and
 * blamed the daemon for not answering — a hang standing in for a "no".
 *
 * The cases here drive the private drain the way {@see DaemonManagerLostSignalLogTest} does,
 * and read the answer where an operator would: at the command server, which is what writes a
 * reply back to the held CLI connection. Reflection reaches the drain because the code-style
 * rule grants tests that exception.
 *
 * The fourth silence — a handler that neither threw nor replied — is deliberately absent: it
 * is not known at any point in this walk, and the ticket says so.
 */
final class DaemonManagerCommandRefusalTest extends TestCase
{
    private const string CORRELATION_ID = 'corr-730';

    protected function tearDown(): void
    {
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testACommandNoAgentOwnsIsRefusedByName(): void
    {
        $manager = new DaemonManagerCommandRefusalTestManager();
        $this->queueCommand(DaemonManagerCommandRefusalTestRouter::UNOWNED_COMMAND);

        $manager->drainQueue();

        $this->assertSame(
            'No agent in this installation answers protected-mode:open',
            $manager->refusalMessage(),
        );
    }

    /**
     * The reply is matched back to its request by correlation id and by nothing else, so an id
     * that does not survive the refusal reaches a terminal that is holding a different one.
     */
    public function testTheRefusalCarriesTheCorrelationIdOfTheRequest(): void
    {
        $manager = new DaemonManagerCommandRefusalTestManager();
        $this->queueCommand(DaemonManagerCommandRefusalTestRouter::UNOWNED_COMMAND);

        $manager->drainQueue();

        $this->assertSame([self::CORRELATION_ID], $manager->deliveredCorrelationIds());
        $this->assertSame(self::CORRELATION_ID, $manager->lastReply()?->correlationId);
        $this->assertSame(CommandConstants::STATUS_ERROR, $manager->lastReply()?->status);
    }

    /**
     * Nothing is held for a request that named no correlation id, so a reply built for it would
     * address nobody — the same clause the group refusal beside it carries.
     */
    public function testARequestWithNoCorrelationIdIsLeftAloneRatherThanAnswered(): void
    {
        $manager = new DaemonManagerCommandRefusalTestManager();
        $this->queueCommand(DaemonManagerCommandRefusalTestRouter::UNOWNED_COMMAND, '');

        $manager->drainQueue();

        $this->assertSame([], $manager->deliveredCorrelationIds());
    }

    /**
     * Two ways the addressed agent goes missing, and they are worded apart because they are
     * fixed apart: one is a placement that never happened, the other a link that broke.
     */
    public function testACommandWhoseAgentNothingPlacedIsRefusedAsUnplaced(): void
    {
        $manager = new DaemonManagerCommandRefusalTestManager();
        $this->queueCommand(DaemonManagerCommandRefusalTestRouter::UNPLACED_COMMAND);

        $manager->drainQueue();

        $this->assertSame(
            'No node of this cluster runs the agent that answers cluster:nodes',
            $manager->refusalMessage(),
        );
    }

    public function testACommandWhoseNodeHasNoLiveLinkIsRefusedAsUnreachable(): void
    {
        $manager = new DaemonManagerCommandRefusalTestManager();
        $this->queueCommand(DaemonManagerCommandRefusalTestRouter::REMOTE_COMMAND);

        $manager->drainQueue();

        $this->assertSame(
            'The node running the agent for cluster:reload is unreachable',
            $manager->refusalMessage(),
        );
    }

    /**
     * The refusals are for commands that reached nobody; one that found its agent must arrive
     * there and leave the terminal waiting on the agent's own answer.
     */
    public function testACommandThatReachesItsAgentIsNotRefused(): void
    {
        $manager = new DaemonManagerCommandRefusalTestManager();
        $this->queueCommand(DaemonManagerCommandRefusalTestRouter::OWNED_COMMAND);

        $manager->drainQueue();

        $this->assertSame([DaemonManagerCommandRefusalTestRouter::AGENT_TYPE], $manager->deliveredTo());
        $this->assertSame([], $manager->deliveredCorrelationIds());
    }

    /**
     * Queues one command request, the shape every case here shares apart from its name.
     *
     * @param string $command Command name the request asks for
     * @param string $correlationId Correlation id the request carries
     */
    private function queueCommand(string $command, string $correlationId = self::CORRELATION_ID): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName($command),
            new CommandRequestDTO($correlationId, $command),
        );
    }
}

/**
 * Daemon manager carrying the two stand-in servers the drain needs: a worker server, without
 * which it returns before looking at the queue, and a command server, which is where a refusal
 * is written back to the operator.
 */
final class DaemonManagerCommandRefusalTestManager extends DaemonManager
{
    /** The stand-in worker server the drain finds and delivers through */
    private DaemonManagerCommandRefusalTestWorkerServer $workerServer;

    /** The stand-in command server the refusals are written to */
    private DaemonManagerCommandRefusalTestCommandServer $commandServer;

    public function __construct()
    {
        parent::__construct();

        $this->workerServer = new DaemonManagerCommandRefusalTestWorkerServer();
        $this->registerServer($this->workerServer);

        $this->commandServer = new DaemonManagerCommandRefusalTestCommandServer();
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
     * @return list<string> Correlation ids the drain wrote a reply for, in order
     */
    public function deliveredCorrelationIds(): array
    {
        return $this->commandServer->deliveredCorrelationIds;
    }

    /**
     * @return ?CommandReplyDTO Last reply the drain wrote, or null when it wrote none
     */
    public function lastReply(): ?CommandReplyDTO
    {
        return $this->commandServer->replies === []
            ? null
            : $this->commandServer->replies[count($this->commandServer->replies) - 1];
    }

    /**
     * Reads the sentence an operator would see, out of the reply's message field.
     *
     * @return ?string Refusal message, or null when no reply carried one
     */
    public function refusalMessage(): ?string
    {
        $payload = $this->lastReply()?->payload ?? [];
        $message = $payload[CommandConstants::FIELD_MESSAGE] ?? null;

        return is_string($message) ? $message : null;
    }

    /**
     * @return list<string> Agent types the drain delivered to, in order
     */
    public function deliveredTo(): array
    {
        return $this->workerServer->deliveredAgentTypes;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new DaemonManagerCommandRefusalTestRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerCommandRefusalTestAgentManagerDaemon();
    }
}

/**
 * Router that answers each test command name with the destination shape its case is about, so
 * all three refusals are reachable without a cluster, a placement view or a project topology.
 */
final class DaemonManagerCommandRefusalTestRouter extends SignalRouter
{
    public const string UNOWNED_COMMAND = 'protected-mode:open';

    public const string UNPLACED_COMMAND = 'cluster:nodes';

    public const string REMOTE_COMMAND = 'cluster:reload';

    public const string OWNED_COMMAND = 'ping';

    public const string AGENT_TYPE = 'command_refusal_test_agent';

    /** Node the remote destination names, which no peer server of this test can reach */
    private const string ABSENT_NODE_ID = 'node-2';

    /**
     * @param SignalDTO $signal Signal being routed
     * @return list<Destination> The one destination this command's case needs, or none
     */
    protected function additionalDestinations(SignalDTO $signal): array
    {
        return match ($signal->signalName->getName()) {
            self::UNPLACED_COMMAND => [new UnknownAgentDestination(self::AGENT_TYPE)],
            self::REMOTE_COMMAND => [new RemoteAgentDestination(self::ABSENT_NODE_ID, self::AGENT_TYPE)],
            self::OWNED_COMMAND => [new AgentDestination(self::AGENT_TYPE)],
            default => [],
        };
    }
}

final class DaemonManagerCommandRefusalTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A command server that records what the drain hands it instead of writing to a socket: the
 * held CLI connection is not what these cases are about, the sentence written to it is.
 */
final class DaemonManagerCommandRefusalTestCommandServer extends CommandServer
{
    /** @var list<string> Correlation ids the drain delivered a reply for, in order */
    public array $deliveredCorrelationIds = [];

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
        $this->deliveredCorrelationIds[] = $correlationId;
        $this->replies[] = $reply;
    }

    protected function onStart(): void
    {
    }
}

/**
 * A worker server that records the handoff instead of starting a process, so the one case that
 * is about a command arriving can tell that it did.
 */
final class DaemonManagerCommandRefusalTestWorkerServer extends WorkerServer
{
    /** @var list<string> Agent types the drain handed a signal to, in order */
    public array $deliveredAgentTypes = [];

    public function __construct()
    {
    }

    /**
     * @param string $agentType Agent type the signal was routed to
     * @param ?string $agentIndex Agent index for a pooled agent, or null
     * @param DaemonAgentMessageDTO $messageDto Signal wrapped for the worker
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->deliveredAgentTypes[] = $agentType;
    }

    protected function onStart(): void
    {
    }
}

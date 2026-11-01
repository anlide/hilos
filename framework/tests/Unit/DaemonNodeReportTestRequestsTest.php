<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\DaemonNodeReportTestRequests;
use Hilos\Core\Daemon\MasterSignalSender;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonMasterReportNowSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The master holds an ask for a node report until a process roster no older than the ask has gone out.
 *
 * Every case names APP_ENV explicitly: the master re-asks the non-production gate at its own door.
 */
final class DaemonNodeReportTestRequestsTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;
    private ?EnvAccessor $previousEnv;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    private ?SignalRouter $previousRouter;
    private string|false $previousAppEnv;
    private NodeReportAgentManager $agents;
    private NodeReportSignalCapture $signals;
    private int $forced = 0;
    /** @var list<CommandReplyDTO> */
    private array $replies = [];
    private DaemonNodeReportTestRequests $requests;

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousEnv = Hilos::$env;
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousAppEnv = getenv('APP_ENV');
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, NodeReportTestHilos::class);
        Hilos::$rt = null;
        Hilos::$cluster = null;
        $this->environment('test');
        $this->agents = new NodeReportAgentManager();
        $this->signals = new NodeReportSignalCapture();
        $this->requests = new DaemonNodeReportTestRequests(
            $this->agents,
            $this->signals,
            function (): void {
                $this->forced++;
            },
            function (CommandReplyDTO $reply): void {
                $this->replies[] = $reply;
            },
        );
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$env = $this->previousEnv;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        putenv($this->previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
        parent::tearDown();
    }

    public function testProductionIsRefusedAtTheMasterDoor(): void
    {
        $this->environment('prod');

        $this->requests->request($this->ask('c-1'), 100.0);

        $this->assertRefusedAtOnce('c-1', "Test-only command 'test:daemon:node:report' is refused: APP_ENV is production-like or unset");
        self::assertSame(
            TestOnlyCommandOnProductionException::message(CliCommands::DAEMON_TEST_NODE_REPORT),
            $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    public function testAFrozenNodeIsRefused(): void
    {
        $runtime = new NodeReportFreezeRtContext();
        $runtime->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        Hilos::$rt = $runtime;

        $this->requests->request($this->ask('c-2'), 100.0);

        $this->assertRefusedAtOnce('c-2', 'No Daemon node report during protected mode.');
    }

    public function testANodeWithoutItsDaemonAgentIsRefused(): void
    {
        $this->agents->started = false;

        $this->requests->request($this->ask('c-3'), 100.0);

        $this->assertRefusedAtOnce('c-3', 'The Daemon node agent is not running on this node.');
    }

    public function testARosterSentBeforeTheAskDoesNotAnswerIt(): void
    {
        $this->requests->request($this->ask('c-4'), 100.0);
        self::assertSame(1, $this->forced);

        $this->requests->dispatchDue(0.0);
        $this->requests->dispatchDue(99.9);
        self::assertSame([], $this->signals->sent);

        $this->requests->dispatchDue(100.0);
        self::assertCount(1, $this->signals->sent);
        self::assertSame(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW, $this->signals->sent[0][2]);
        self::assertSame([], $this->replies, 'The node agent answers the console, not the master');
    }

    public function testAFailedRosterAnswersEveryWait(): void
    {
        $this->requests->request($this->ask('c-5'), 100.0);
        $this->requests->request($this->ask('c-6'), 100.2);

        $this->requests->fail('The node could not build its process roster: LogicException: no workers');

        self::assertSame(['c-5', 'c-6'], array_map(static fn (CommandReplyDTO $reply): string => $reply->correlationId, $this->replies));
        foreach ($this->replies as $reply) {
            self::assertFalse($reply->isOk());
            self::assertSame(
                'The node could not build its process roster: LogicException: no workers',
                $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null,
            );
        }
        $this->requests->dispatchDue(200.0);
        self::assertSame([], $this->signals->sent);
    }

    public function testAnUnansweredWaitEndsAtTheAgentWindow(): void
    {
        $this->requests->request($this->ask('c-7'), 100.0);

        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS - 0.1);
        self::assertSame([], $this->replies);

        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);
        self::assertCount(1, $this->replies);
        self::assertSame('c-7', $this->replies[0]->correlationId);
        self::assertSame('The Daemon node agent did not report in time.', $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE] ?? null);

        $this->requests->dispatchDue(200.0);
        self::assertSame([], $this->signals->sent);
    }

    public function testACallerWhoLeftIsNeitherAskedForNorAnswered(): void
    {
        $this->requests->request($this->ask('c-8'), 100.0);

        $this->requests->abandon('c-8');
        $this->requests->dispatchDue(100.0);
        $this->requests->tick(200.0);

        self::assertSame([], $this->signals->sent);
        self::assertSame([], $this->replies);
    }

    public function testTwoAsksGetTwoSignals(): void
    {
        $this->requests->request($this->ask('c-9'), 100.0);
        $this->requests->request($this->ask('c-10'), 100.0);

        $this->requests->dispatchDue(100.0);
        $this->requests->dispatchDue(100.5);

        self::assertCount(2, $this->signals->sent);
        $asked = [];
        foreach ($this->signals->sent as [$type, $index, $name, $data]) {
            self::assertSame(HilosAgentType::HILOS_DAEMON_NODE, $type);
            self::assertNull($index);
            self::assertSame(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW, $name);
            self::assertInstanceOf(DaemonMasterReportNowSignalData::class, $data);
            self::assertSame('standalone', $data->nodeId);
            $asked[] = $data->correlationId;
        }
        self::assertSame(['c-9', 'c-10'], $asked);
    }

    /** A correlation of digits only is kept under an int key; the signal to the node agent still names it as a string. */
    public function testADigitOnlyCorrelationIsNamedByTheSignalToTheNodeAgent(): void
    {
        $this->requests->request($this->ask('1234567890123456'), 100.0);

        $this->requests->dispatchDue(100.0);

        self::assertCount(1, $this->signals->sent);
        $data = $this->signals->sent[0][3];
        self::assertInstanceOf(DaemonMasterReportNowSignalData::class, $data);
        self::assertSame('1234567890123456', $data->correlationId);
        self::assertSame([], $this->replies, 'The node agent answers the console, not the master');
        $this->requests->dispatchDue(200.0);
        $this->requests->tick(200.0);
        self::assertCount(1, $this->signals->sent);
        self::assertSame([], $this->replies);
    }

    /** A correlation of digits only is kept under an int key; the failed roster still names it as a string. */
    public function testADigitOnlyCorrelationIsAnsweredByAFailedRoster(): void
    {
        $this->requests->request($this->ask('1234567890123456'), 100.0);
        $this->requests->request($this->ask('6543210987654321'), 100.2);

        $this->requests->fail('The node could not build its process roster: LogicException: no workers');

        self::assertSame(
            ['1234567890123456', '6543210987654321'],
            array_map(static fn (CommandReplyDTO $reply): string => $reply->correlationId, $this->replies),
        );
        foreach ($this->replies as $reply) {
            self::assertFalse($reply->isOk());
            self::assertSame(
                'The node could not build its process roster: LogicException: no workers',
                $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null,
            );
        }
        $this->requests->dispatchDue(200.0);
        self::assertSame([], $this->signals->sent);
    }

    /** A correlation of digits only is kept under an int key; the agent window still names it as a string. */
    public function testADigitOnlyCorrelationIsAnsweredByTheAgentWindow(): void
    {
        $this->requests->request($this->ask('1234567890123456'), 100.0);

        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);

        self::assertCount(1, $this->replies);
        self::assertSame('1234567890123456', $this->replies[0]->correlationId);
        self::assertFalse($this->replies[0]->isOk());
        self::assertSame('The Daemon node agent did not report in time.', $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE] ?? null);
        $this->requests->dispatchDue(200.0);
        self::assertSame([], $this->signals->sent);
    }

    /**
     * Driven through the master: a roster built over two passes answers the ask only once sent.
     *
     * A whole roster went out before the ask and does not answer it; the forced roster after it
     * takes two passes, and the node agent is asked in the pass it goes out, after it. The node
     * has no HTTP server, so its HTTP frame never goes out, and the ask is answered without it.
     */
    public function testTheMasterAsksOnlyAfterAMultiPassRosterHasGoneOut(): void
    {
        $manager = new NodeReportTestDaemonManager();
        $server = $manager->addWorkers(150);
        $manager->passAt(100.0);
        $manager->passAt(100.1);
        self::assertSame(1, $server->count(HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER));

        $manager->askDuringPassAt(101.0, $this->ask('c-11'));
        self::assertSame(1, $server->count(HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER));
        self::assertSame(0, $server->count(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW));

        $manager->passAt(101.1);
        self::assertSame(2, $server->count(HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER));
        self::assertSame(1, $server->count(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW));
        $last = array_key_last($server->delivered);
        self::assertSame(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW, $server->name($last));
        $ask = $server->delivered[$last]->signal->data->data;
        self::assertInstanceOf(DaemonMasterReportNowSignalData::class, $ask);
        self::assertSame('standalone', $ask->nodeId);
        self::assertSame('c-11', $ask->correlationId);
        self::assertSame(0, $server->count(HilosSignalConstants::DAEMON_MASTER_HTTP));

        $manager->passAt(101.2);
        self::assertSame(1, $server->count(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW));
    }

    /**
     * @param string $correlationId Console correlation
     * @return CommandRequestDTO Held request as the command channel hands it over
     */
    private function ask(string $correlationId): CommandRequestDTO
    {
        return new CommandRequestDTO($correlationId, CliCommands::DAEMON_TEST_NODE_REPORT, []);
    }

    /** @param string $appEnv Environment the master judges */
    private function environment(string $appEnv): void
    {
        putenv("APP_ENV={$appEnv}");
        Hilos::$env = new EnvAccessor();
    }

    /**
     * @param string $correlationId Refused console correlation
     * @param string $message Exact refusal
     */
    private function assertRefusedAtOnce(string $correlationId, string $message): void
    {
        self::assertCount(1, $this->replies);
        self::assertSame($correlationId, $this->replies[0]->correlationId);
        self::assertFalse($this->replies[0]->isOk());
        self::assertSame($message, $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE] ?? null);
        self::assertSame(0, $this->forced, 'A refused ask forces no frames');
        $this->requests->dispatchDue(200.0);
        $this->requests->tick(200.0);
        self::assertSame([], $this->signals->sent);
        self::assertCount(1, $this->replies);
    }
}

/** Master test double driving the frame part of one main-loop pass. */
final class NodeReportTestDaemonManager extends DaemonManager
{
    private const array FRAME_TICKS = ['tickProcessRoster', 'tickCronFrame', 'tickStanding', 'tickHttpFrame', 'tickMeasurements'];

    /**
     * @param int $count Live workers without a pid, one roster row each
     * @return NodeReportWorkerServer Worker source registered with this master
     */
    public function addWorkers(int $count): NodeReportWorkerServer
    {
        $server = new NodeReportWorkerServer($count);
        $this->registerServer($server);
        return $server;
    }

    /** @param float $now Controlled start of the pass */
    public function passAt(float $now): void
    {
        new ReflectionProperty(DaemonManager::class, 'loopPassStartedAt')->setValue($this, $now);
        $this->framesAndAsks($now);
    }

    /**
     * @param float $now Controlled start of the pass
     * @param CommandRequestDTO $request Ask arriving on the command socket in this pass
     */
    public function askDuringPassAt(float $now, CommandRequestDTO $request): void
    {
        new ReflectionProperty(DaemonManager::class, 'loopPassStartedAt')->setValue($this, $now);
        $this->requestNodeReport($request);
        $this->framesAndAsks($now);
    }

    /** @return SignalRouter Empty routing table for directly addressed master frames */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Manager that reports the node agent started */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new NodeReportAgentManager();
    }

    /** @param float $now Controlled start of the pass */
    private function framesAndAsks(float $now): void
    {
        foreach (self::FRAME_TICKS as $tick) {
            new ReflectionMethod(DaemonManager::class, $tick)->invoke($this, $now);
        }
        $requests = new ReflectionProperty(DaemonManager::class, 'daemonNodeReportTestRequests')->getValue($this);
        $requests->dispatchDue(new ReflectionProperty(DaemonManager::class, 'processRosterLastSentAt')->getValue($this));
    }
}

/** Worker source with pid-less workers and captured addressed frames. */
final class NodeReportWorkerServer extends WorkerServer
{
    /** @var list<DaemonAgentMessageDTO> */
    public array $delivered = [];

    /** @param int $workers Number of live workers to report */
    public function __construct(private readonly int $workers)
    {
    }

    /** @return list<DaemonWorkerPicture> Live workers without a pid */
    public function liveWorkerPictures(): array
    {
        $pictures = [];
        for ($index = 1; $index <= $this->workers; $index++) {
            $pictures[] = new DaemonWorkerPicture($index, WorkerConstants::TYPE_REGULAR, null, null, []);
        }
        return $pictures;
    }

    /**
     * @param string $agentType Receiver type
     * @param ?string $agentIndex Receiver index
     * @param DaemonAgentMessageDTO $messageDto Addressed master frame
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->delivered[] = $messageDto;
    }

    /**
     * @param string $signalName Signal name
     * @return int Frames of that name delivered so far
     */
    public function count(string $signalName): int
    {
        return count(array_filter(
            $this->delivered,
            static fn (DaemonAgentMessageDTO $frame): bool => $frame->signal->signalName->getName() === $signalName,
        ));
    }

    /**
     * @param int $index Delivery position
     * @return string Signal name delivered there
     */
    public function name(int $index): string
    {
        return $this->delivered[$index]->signal->signalName->getName();
    }

    protected function onStart(): void
    {
    }
}

/** Capture of the master's addressed sends. */
final class NodeReportSignalCapture implements MasterSignalSender
{
    /** @var list<array{string, ?string, string, SignalDataInterface}> */
    public array $sent = [];

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Payload
     */
    public function sendToAgent(string $agentType, ?string $agentIndex, string $signalName, SignalDataInterface $data): void
    {
        $this->sent[] = [$agentType, $agentIndex, $signalName, $data];
    }

    /**
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Payload
     * @throws LogicException Always: the ask addresses one agent
     */
    public function sendToWorkers(string $signalName, SignalDataInterface $data): void
    {
        throw new LogicException('The node report ask addresses one agent');
    }
}

/** Agent roster with a switchable node agent. */
final class NodeReportAgentManager extends AgentManagerDaemon
{
    public bool $started = true;

    /**
     * @param string $agentId Receiver id
     * @return bool Whether the node agent is up
     */
    public function isAgentStarted(string $agentId): bool
    {
        return $this->started && $agentId === HilosAgentType::HILOS_DAEMON_NODE;
    }

    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused instance index
     * @return AgentDaemonInterface Never returned
     * @throws LogicException Always: the test starts no agents
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('The node report test starts no agents');
    }
}

/** Runtime facade exposing only the phase the master reads. */
final class NodeReportFreezeRtContext extends RtContext
{
    public object $hilosProtectedModeRuntime;

    public function __construct()
    {
        $this->hilosProtectedModeRuntime = (object)['phase' => StateProtectedModeRuntime::PHASE_INACTIVE];
    }

    public function configure(): void
    {
    }
}

/** Topology with the node receiver declared as a per-node agent. */
abstract class NodeReportTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON_NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}

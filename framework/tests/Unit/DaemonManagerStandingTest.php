<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ConsensusInspection;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\Leadership;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DTO\DaemonMasterStandingSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** The master's standing frame uses only its own handshaked sockets and local consensus. */
final class DaemonManagerStandingTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    private ?EnvAccessor $previousEnv;

    /** Replaces global facade inputs with this test's node and agent topology. */
    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = Hilos::$env;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StandingTestHilos::class);
        Hilos::$rt = null;
        Hilos::$cluster = null;
        Hilos::$env = new EnvAccessor();
    }

    /** Restores the facade and cluster environment after each case. */
    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        putenv('CLUSTER_ENABLED');
        putenv('CLUSTER_NODE_ID');
        putenv('CLUSTER_NODE_ROLE');
        parent::tearDown();
    }

    /** Counts only this master's handshaked sockets, grouping tabs by session hash. */
    public function testOwnHandshakedConnectionsAndDistinctSessionsAreCounted(): void
    {
        $manager = new StandingTestDaemonManager();
        $webSocket = $manager->addBrowserServer();
        $worker = $manager->addCaptureServer();
        $webSocket->connect('', 'incomplete');
        $webSocket->connect('tab-a', 'browser-one');
        $webSocket->connect('tab-b', 'browser-one');
        $webSocket->connect('tab-c', 'browser-two');

        $manager->tickStandingAt(100.0);
        self::assertSame(2, $worker->frame(0)->standing->sessions);
        self::assertSame(3, $worker->frame(0)->standing->connections);
        self::assertFalse($worker->frame(0)->standing->clustered);
        self::assertNull($worker->frame(0)->standing->consensus);

        $manager->tickStandingAt(100.5);
        self::assertCount(1, $worker->delivered);
        $manager->tickStandingAt(101.0);
        self::assertCount(1, $worker->delivered);
        $webSocket->connect('tab-d', 'browser-two');
        $manager->tickStandingAt(102.0);
        self::assertSame(2, $worker->frame(1)->standing->sessions);
        self::assertSame(4, $worker->frame(1)->standing->connections);
        $manager->tickStandingAt(161.0);
        self::assertCount(2, $worker->delivered);
        $manager->tickStandingAt(162.0);
        self::assertCount(3, $worker->delivered);
    }

    /** A freeze suppresses frames; restart forces one; a refusal repairs at the minute. */
    public function testFreezeAgentRestartAndDeliveryRetry(): void
    {
        $manager = new StandingTestDaemonManager();
        $worker = $manager->addCaptureServer();
        $manager->agents()->started = false;
        $manager->tickStandingAt(100.0);
        self::assertSame([], $worker->delivered);

        $manager->agents()->started = true;
        $runtime = new StandingTestFreezeRtContext();
        $runtime->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        Hilos::$rt = $runtime;
        $manager->tickStandingAt(101.0);
        self::assertSame([], $worker->delivered);

        $runtime->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_INACTIVE;
        $manager->tickStandingAt(102.0);
        self::assertCount(1, $worker->delivered);
        $manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $manager->tickStandingAt(102.1);
        self::assertCount(2, $worker->delivered);

        $worker->refuse = true;
        $manager->tickStandingAt(162.1);
        self::assertSame(3, $worker->attempts);
        $manager->tickStandingAt(163.1);
        self::assertSame(3, $worker->attempts);
        $worker->refuse = false;
        $manager->tickStandingAt(222.1);
        self::assertSame(4, $worker->attempts);
        self::assertCount(3, $worker->delivered);
    }

    /** Only a clustered master with a coordinator reports election details. */
    public function testClusteredMasterHasConsensusAndSlaveDoesNot(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=n1');
        putenv('CLUSTER_NODE_ROLE=master');
        $cluster = new ClusterContext();
        $cluster->registerLeadership(new StandingTestConsensus());
        Hilos::$cluster = $cluster;

        $manager = new StandingTestDaemonManager();
        $worker = $manager->addCaptureServer();
        $manager->tickStandingAt(100.0);
        $standing = $worker->frame(0)->standing;
        self::assertTrue($standing->clustered);
        self::assertSame(ConsensusRole::Leader, $standing->consensus?->role);
        self::assertSame(4, $standing->consensus?->term);
        self::assertSame('n1', $standing->consensus?->leaderId);
        self::assertSame([2, 3, 2], [
            $standing->consensus?->onlineMasters,
            $standing->consensus?->masters,
            $standing->consensus?->quorumSize,
        ]);

        putenv('CLUSTER_NODE_ROLE=slave');
        Hilos::$cluster = new ClusterContext();
        $manager = new StandingTestDaemonManager();
        $worker = $manager->addCaptureServer();
        $manager->tickStandingAt(100.0);
        self::assertTrue($worker->frame(0)->standing->clustered);
        self::assertNull($worker->frame(0)->standing->consensus);
    }

    /** A failed sample is retried after one minute, even if config recovers sooner. */
    public function testUnreadableClusterConfigurationRetriesAfterOneMinute(): void
    {
        putenv('CLUSTER_ENABLED=unreadable');
        Hilos::$cluster = new ClusterContext();
        $manager = new StandingTestDaemonManager();
        $worker = $manager->addCaptureServer();

        $manager->tickStandingAt(100.0);
        self::assertSame(0, $worker->attempts);
        putenv('CLUSTER_ENABLED=false');
        $manager->tickStandingAt(101.0);
        self::assertSame(0, $worker->attempts);
        $manager->tickStandingAt(160.0);
        self::assertSame(1, $worker->attempts);
        self::assertFalse($worker->frame(0)->standing->clustered);
    }
}

final class StandingTestDaemonManager extends DaemonManager
{
    /** @return StandingTestCaptureServer Capturing local worker server */
    public function addCaptureServer(): StandingTestCaptureServer
    {
        $server = new StandingTestCaptureServer();
        $this->registerServer($server);
        return $server;
    }

    /** @return StandingTestWebSocketServer Mutable local browser server */
    public function addBrowserServer(): StandingTestWebSocketServer
    {
        $server = new StandingTestWebSocketServer();
        $this->registerServer($server);
        return $server;
    }

    /** @return StandingTestAgentManager Mutable receiver presence */
    public function agents(): StandingTestAgentManager
    {
        return $this->agentManagerDaemon;
    }

    /** @param float $now Controlled loop time */
    public function tickStandingAt(float $now): void
    {
        new ReflectionMethod(DaemonManager::class, 'tickStanding')->invoke($this, $now);
    }

    /** @return SignalRouter Empty route table for addressed frames */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Manager with mutable receiver presence */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new StandingTestAgentManager();
    }
}

final class StandingTestCaptureServer extends WorkerServer
{
    /** @var list<DaemonAgentMessageDTO> */
    public array $delivered = [];
    public int $attempts = 0;
    public bool $refuse = false;

    /** Builds a socketless receiver. */
    public function __construct()
    {
    }

    /**
     * @param string $agentType Receiver type
     * @param ?string $agentIndex Receiver index
     * @param DaemonAgentMessageDTO $messageDto Addressed frame
     * @throws LogicException When this test refuses delivery
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->attempts++;
        if ($this->refuse) {
            throw new LogicException('Delivery refused by test');
        }
        $this->delivered[] = $messageDto;
    }

    /**
     * @param int $index Captured frame index
     * @return DaemonMasterStandingSignalData Sent standing frame
     * @throws LogicException When the captured frame has another type
     */
    public function frame(int $index): DaemonMasterStandingSignalData
    {
        $data = $this->delivered[$index]->signal->data->data;
        if (!$data instanceof DaemonMasterStandingSignalData) {
            throw new LogicException('Captured frame is not standing');
        }
        return $data;
    }

    /** Starts no real socket in this capture server. */
    protected function onStart(): void
    {
    }
}

final class StandingTestWebSocketServer extends WebSocketServer
{
    /** Builds a socketless browser server. */
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * @param string $acceptKey Browser connection key
     * @param string $sessionHash Browser session identity
     */
    public function connect(string $acceptKey, string $sessionHash): void
    {
        $client = WebSocketClientTestProbe::createSocketless();
        $client->acceptKey = $acceptKey;
        new ReflectionProperty(WebSocketClient::class, 'sessionTokenHashValue')->setValue($client, $sessionHash);
        $this->clients[] = $client;
    }

    /** Starts no real socket in this browser server. */
    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Unused accepted socket
     * @return WebSocketClientInterface Never returned
     * @throws InvalidStateException Always; this server accepts nothing
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new InvalidStateException('The standing test accepts no connection');
    }
}

final class StandingTestAgentManager extends AgentManagerDaemon
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
     * @throws LogicException Always; this test starts no agent
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('The standing test starts no agents');
    }
}

final class StandingTestFreezeRtContext extends RtContext
{
    public object $hilosProtectedModeRuntime;

    /** Starts with the inactive phase so cases can set a freeze explicitly. */
    public function __construct()
    {
        $this->hilosProtectedModeRuntime = (object)['phase' => StateProtectedModeRuntime::PHASE_INACTIVE];
    }

    /** Registers no runtime collections. */
    public function configure(): void
    {
    }
}

abstract class StandingTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON_NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}

final class StandingTestConsensus implements Leadership, ConsensusInspection
{
    /** @return bool This test master leads */
    public function amLeader(): bool
    {
        return true;
    }

    /** @return ?string This master's leader id */
    public function leaderId(): ?string
    {
        return 'n1';
    }

    /** @return bool No remote leader's browser server is followed */
    public function leaderWebSocketOpen(): bool
    {
        return false;
    }

    /** @return bool This test master sees a quorum */
    public function hasQuorum(): bool
    {
        return true;
    }

    /** @return int Fixed test term */
    public function term(): int
    {
        return 4;
    }

    /** @return ConsensusRole Fixed test role */
    public function consensusRole(): ConsensusRole
    {
        return ConsensusRole::Leader;
    }

    /** @return int Fixed visible master count */
    public function onlineMasterCount(): int
    {
        return 2;
    }

    /** @return int Static test master-set size */
    public function masterSetSize(): int
    {
        return 3;
    }

    /** @return int Static test quorum size */
    public function quorumSize(): int
    {
        return 2;
    }
}

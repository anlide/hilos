<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\NullLeadershipObserver;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** Pins browser admission on the local role and the current leader's word (HIL-1304). */
final class DaemonManagerWebSocketReadinessTest extends TestCase
{
    private const float EXPIRED_READINESS_WAIT_SECONDS = 1.0;

    private const float READINESS_TIMEOUT_SECONDS = 0.5;

    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        foreach (['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE'] as $key) {
            putenv($key);
        }

        parent::tearDown();
    }

    public function testSlaveNeverOpensEvenWhenWorkersAreReady(): void
    {
        $this->cluster('slave');
        [$manager, $server] = $this->readyManager();
        $manager->setTimeout(0.0);

        $this->tick($manager);

        $this->assertSame(0, $server->startCount);
    }

    public function testFollowerWaitsForTheLeadersOpenWebSocketHeartbeat(): void
    {
        $coordinator = $this->cluster('master');
        [$manager, $server] = $this->readyManager();

        $this->tick($manager);
        $this->assertSame(0, $server->startCount);
        $this->assertSame(0, $manager->requiredAgentReads);

        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'm1', true));
        $this->tick($manager);

        $this->assertSame(1, $server->startCount);
        $this->assertSame(0, $manager->requiredAgentReads);
    }

    public function testFollowerOpensDegradedAfterTheSharedTimeout(): void
    {
        // FIXME(2026-10-04): parked on the owner's word ahead of the HIL-1186 and HIL-1232 full runs,
        // a flake foreign to both. Red once in a full run (0783, on HIL-1304's own branch) with
        // "Failed asserting that 0 is identical to 1" at the start count below, and green on the
        // rerun of the same sha. Paid off by finding what the tick waits on besides the timeout,
        // this skip removed, and the test green in test:framework:unit.
        $this->markTestSkipped('FIXME(2026-10-04): flaky - the follower once did not open after the shared timeout (run 0783)');
        $this->cluster('master');
        [$manager, $server] = $this->readyManager();
        $manager->setTimeout(self::READINESS_TIMEOUT_SECONDS);

        $this->tick($manager);

        $this->assertSame(1, $server->startCount);
    }

    public function testLeaderStillWaitsForItsRequiredAgent(): void
    {
        $coordinator = $this->cluster('master');
        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'm1'));
        [$manager, $server] = $this->readyManager();

        $this->tick($manager);
        $this->assertSame(0, $server->startCount);
        $this->assertSame(1, $manager->requiredAgentReads);

        $manager->agentStarted = true;
        $this->tick($manager);
        $this->assertSame(1, $server->startCount);
    }

    public function testOpenedFollowerDoesNotOpenAgainAfterBecomingLeader(): void
    {
        $coordinator = $this->cluster('master');
        [$manager, $server] = $this->readyManager();
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'm1', true));
        $this->tick($manager);

        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(2, true, 'm1'));
        $this->tick($manager);

        $this->assertSame(1, $server->startCount);
    }

    public function testOpeningRecordsReadinessForLaterLeaderHeartbeats(): void
    {
        $mesh = new ReadinessTestMesh();
        $coordinator = $this->cluster('master', $mesh);
        [$manager] = $this->readyManager();
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'm1', true));

        $this->tick($manager);
        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(2, true, 'm1'));
        $coordinator->tick(1.1);

        $this->assertTrue($mesh->lastHeartbeat?->webSocketOpen);
    }

    public function testAgentStartRosterIncludesKeysOnOtherNodes(): void
    {
        $context = new ClusterContext();
        $connections = new ClusterClientLocation();
        $connections->applySnapshot('m1', ['remote-key']);
        $context->registerClientConnections($connections);
        Hilos::$cluster = $context;

        $manager = new ReadinessTestManager();

        $this->assertSame(['remote-key'], $manager->liveAcceptKeys());

        Hilos::$cluster = null;
        $this->assertSame([], $manager->liveAcceptKeys());
    }

    /**
     * @param string $role Local cluster role
     * @param ?ReadinessTestMesh $mesh Mesh to observe, or a fresh one
     * @return ClusterCoordinator Installed local coordinator
     */
    private function cluster(string $role, ?ReadinessTestMesh $mesh = null): ClusterCoordinator
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=m2');
        putenv('CLUSTER_NODE_ROLE=' . $role);
        $coordinator = new ClusterCoordinator(
            new ClusterConsensusConfig('m2', ['m1', 'm2', 'm3'], 2, 1000, 1000, 100),
            $mesh ?? new ReadinessTestMesh(),
            new NullLeadershipObserver(),
        );
        $context = new ClusterContext();
        $context->registerLeadership($coordinator);
        Hilos::$cluster = $context;

        return $coordinator;
    }

    /**
     * @return array{ReadinessTestManager, ReadinessTestWebSocketServer} Ready test daemon and browser server
     */
    private function readyManager(): array
    {
        $manager = new ReadinessTestManager();
        $server = new ReadinessTestWebSocketServer();
        $manager->registerServer($server);
        new ReflectionProperty(DaemonManager::class, 'workersReady')->setValue($manager, true);
        new ReflectionProperty(DaemonManager::class, 'readinessWaitSince')->setValue(
            $manager,
            microtime(true) - self::EXPIRED_READINESS_WAIT_SECONDS,
        );

        return [$manager, $server];
    }

    /** @param ReadinessTestManager $manager Daemon to advance */
    private function tick(ReadinessTestManager $manager): void
    {
        new ReflectionMethod(DaemonManager::class, 'tickReadiness')->invoke($manager);
    }
}

/** Daemon with one required agent and an adjustable readiness timeout. */
final class ReadinessTestManager extends DaemonManager
{
    public bool $agentStarted = false;

    public int $requiredAgentReads = 0;

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ReadinessTestAgentManager($this);
    }

    /** @return list<string> One critical agent id */
    protected function getRequiredReadinessAgents(): array
    {
        $this->requiredAgentReads++;
        return ['critical'];
    }

    /** @param ?float $seconds Readiness timeout in seconds */
    public function setTimeout(?float $seconds): void
    {
        $this->readinessTimeout = $seconds;
    }
}

/** Agent manager that reports the test's critical agent readiness. */
final class ReadinessTestAgentManager extends AgentManagerDaemon
{
    /** @param ReadinessTestManager $manager Test daemon holding readiness state */
    public function __construct(private readonly ReadinessTestManager $manager)
    {
    }

    /**
     * @param string $agentId Agent id to check
     * @return bool Whether the critical agent has started
     */
    public function isAgentStarted(string $agentId): bool
    {
        return $this->manager->agentStarted;
    }

    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused agent index
     * @return AgentDaemonInterface Never returned
     * @throws AgentDaemonCreationFailedException Always; this test starts no agents
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('readiness test starts no agent');
    }
}

/** Browser server that records openings without binding a socket. */
final class ReadinessTestWebSocketServer extends WebSocketServer
{
    public int $startCount = 0;

    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /** @return bool True after recording the opening */
    public function start(): bool
    {
        $this->startCount++;
        $this->isRunning = true;
        return true;
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Unused accepted socket
     * @return WebSocketClientInterface Never returned
     * @throws SocketException Always; this test accepts no browsers
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new SocketException('readiness test accepts no browsers');
    }
}

/** Mesh with all three masters online and the last heartbeat observable. */
final class ReadinessTestMesh implements ConsensusMesh
{
    public ?PeerHeartbeatDTO $lastHeartbeat = null;

    /** @return list<string> The three online masters */
    public function onlineMasterIds(): array
    {
        return ['m1', 'm2', 'm3'];
    }

    /** @param PeerDTO $frame Frame to record when it is a heartbeat */
    public function broadcastToMasters(PeerDTO $frame): void
    {
        if ($frame instanceof PeerHeartbeatDTO) {
            $this->lastHeartbeat = $frame;
        }
    }

    /**
     * @param string $nodeId Unused target
     * @param PeerDTO $frame Unused frame
     */
    public function sendToMaster(string $nodeId, PeerDTO $frame): void
    {
    }
}

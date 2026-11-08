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
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalName;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
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

    /** @var list<array{ReadinessTestManager, EventLoop}> Native bindings released after each case */
    private array $bindings = [];

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        foreach ($this->bindings as [$manager, $loop]) {
            new ReflectionMethod(DaemonManager::class, 'detachReadinessEventLoop')->invoke($manager);
            new ReflectionMethod(DaemonManager::class, 'detachCronEventLoop')->invoke($manager);
            $loop->close();
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        foreach (['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_NAME', 'CLUSTER_NODE_ROLE'] as $key) {
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

    public function testWorkersReadySignalWakesTheNativeGateWithoutAMasterTick(): void
    {
        Hilos::$cluster = null;
        [$manager, $server] = $this->readyManager();
        $manager->agentStarted = true;
        $manager->registerServer(new ReadinessTestWorkerServer());
        new ReflectionProperty(DaemonManager::class, 'workersReady')->setValue($manager, false);
        $loop = $this->bind($manager);
        new ReflectionMethod(DaemonManager::class, 'addCronRule')->invoke($manager, 'native-ready', '* * * * *');
        new ReflectionMethod(DaemonManager::class, 'attachCronEventLoop')->invoke($manager);
        $cronTimer = new ReflectionProperty(DaemonManager::class, 'cronMinuteTimer')->getValue($manager);
        $this->assertNull($cronTimer->getDeadline());
        $loop->tick();
        $this->assertSame(0, $manager->requiredAgentReads);
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(SignalConstants::WORKERS_READY),
            new SystemSignalDTO(systemName: SignalConstants::WORKERS_READY),
        );
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($manager);
        $loop->tick();
        new ReflectionMethod(DaemonManager::class, 'dispatchCronMinute')->invoke($manager);
        $this->assertNotNull($cronTimer->getDeadline());
        $this->assertSame(1, $server->startCount);
        $this->assertFalse($loop->hasReadyWork());
    }

    public function testRealAgentStartReportOpensWithoutAnyMasterTickOrIoScan(): void
    {
        Hilos::$cluster = null;
        [$manager, $server] = $this->readyManager();
        $manager->useRealRoster = true;
        $agents = $manager->getAgentManagerDaemon();
        $agents->addAgent('critical', $this->createMock(AgentDaemonInterface::class), 1, false);
        $loop = $this->bind($manager);
        $loop->tick();
        $deadline = $this->timer($manager)->getDeadline();
        $this->assertSame(0, $server->startCount);
        $this->assertSame(1, $manager->requiredAgentReads);
        $loop->createReadyWork(static function (): void {})->markReady();
        $loop->tick();
        $loop->tick();
        $this->assertSame(1, $manager->requiredAgentReads, 'unrelated work never scans startup agents');
        $this->assertSame($deadline, $this->timer($manager)->getDeadline());
        $this->assertFalse($loop->hasReadyWork());

        $agents->handleAgentStarted(new WorkerAgentStartedDTO('critical', 'critical'));
        $loop->tick();
        $this->assertSame(1, $server->startCount);
        $this->assertNull($this->timer($manager));
        $agents->handleAgentStarted(new WorkerAgentStartedDTO('critical', 'critical'));
        $loop->tick();
        $this->assertSame(1, $server->startCount);
        $this->assertFalse($loop->hasReadyWork());
    }

    public function testLeaderHeartbeatWakesOnlyForAChangedReadinessFact(): void
    {
        $coordinator = $this->cluster('master');
        [$manager, $server] = $this->readyManager();
        $loop = $this->bind($manager);
        $loop->tick();
        $deadline = $this->timer($manager)->getDeadline();
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'm1', false));
        $loop->tick();
        $this->assertFalse($loop->hasReadyWork());
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'm1', false));
        $this->assertFalse($loop->hasReadyWork(), 'the same heartbeat verdict owes no gate reevaluation');
        $this->assertSame($deadline, $this->timer($manager)->getDeadline());
        $coordinator->noteNodeOffline('m1');
        $this->assertTrue($loop->hasReadyWork(), 'leader loss is a readiness fact');
        $loop->tick();
        $this->assertSame($deadline, $this->timer($manager)->getDeadline());
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(2, 'm3', false));
        $loop->tick();
        $this->assertSame(0, $server->startCount);
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(2, 'm3', true));
        $loop->tick();
        $this->assertSame(1, $server->startCount);
        $this->assertNull($this->timer($manager));
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(3, 'm1', true));
        $this->assertFalse($loop->hasReadyWork(), 'opened gate releases the coordinator callback');
    }

    public function testNativeTimeoutOpensDegradedAndNullTimeoutKeepsWaiting(): void
    {
        foreach ([false, true] as $follower) {
            if ($follower) {
                $this->cluster('master');
            } else {
                Hilos::$cluster = null;
            }
            [$manager, $server] = $this->readyManager();
            $loop = $this->bind($manager);
            $loop->tick();
            $timer = $this->timer($manager);
            $this->assertNotNull($timer->getDeadline());
            new ReflectionProperty(DaemonManager::class, 'readinessWaitSince')->setValue($manager, hrtime(true) / 1e9 - 61.0);
            $timer->armAt(hrtime(true) / 1e9 - 1.0);
            $this->pump($loop);
            $this->assertSame(0, $server->startCount, 'null timeout remains an unlimited wait after the stuck log');
            $this->assertNotNull(new ReflectionProperty(DaemonManager::class, 'lastReadinessLogAt')->getValue($manager));
            $this->assertNotNull($timer->getDeadline(), 'only the next stuck log remains armed');
            $this->assertFalse($loop->hasReadyWork());

            $manager->setTimeout(self::READINESS_TIMEOUT_SECONDS);
            $timer->armAt(hrtime(true) / 1e9 - 1.0);
            $this->pump($loop);
            $this->assertSame(1, $server->startCount);
            $this->assertNull($this->timer($manager));
        }
    }

    public function testNativeReadinessCannotOpenOnSlaveOrAfterShutdown(): void
    {
        $coordinator = $this->cluster('slave');
        [$manager, $server] = $this->readyManager();
        $manager->setTimeout(0.0);
        $loop = $this->bind($manager);
        $this->pump($loop);
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'm1', true));
        $this->assertSame(0, $server->startCount);
        $this->assertNull($this->timer($manager));
        $this->assertFalse($loop->hasReadyWork());

        $this->cluster('master');
        [$manager, $server] = $this->readyManager();
        $loop = $this->bind($manager);
        $loop->tick();
        $timer = $this->timer($manager);
        $timer->armAt(hrtime(true) / 1e9 - 1.0);
        new ReflectionProperty(DaemonManager::class, 'shutdownStartTime')->setValue($manager, microtime(true));
        new ReflectionMethod(DaemonManager::class, 'initiateShutdown')->invoke($manager);
        $this->pump($loop);
        $manager->agentStarted = true;
        $manager->onAgentStarted('critical');
        $this->pump($loop);
        $this->assertSame(0, $server->startCount);
        $this->assertNull($this->timer($manager));
        $this->assertFalse($loop->hasReadyWork());
    }

    public function testStopAndFailureFactsInvalidateABoundedAgentWalk(): void
    {
        Hilos::$cluster = null;
        [$manager, $server] = $this->readyManager();
        $manager->useRealRoster = true;
        $manager->requiredAgents = array_merge(['critical'], array_fill(0, 129, 'supporting'));
        $agents = $manager->getAgentManagerDaemon();
        $agents->addAgent('critical', $this->createMock(AgentDaemonInterface::class), 1, false);
        $agents->handleAgentStarted(new WorkerAgentStartedDTO('critical', 'critical'));
        $agents->addAgent('supporting', $this->createMock(AgentDaemonInterface::class), 2, false);
        $agents->handleAgentStarted(new WorkerAgentStartedDTO('supporting', 'supporting'));
        $loop = $this->bind($manager);
        $loop->tick();
        $this->assertSame(0, $server->startCount);
        $this->assertTrue($loop->hasReadyWork(), 'an unfinished bounded walk continues without waiting');
        $agents->handleAgentStopped(new WorkerAgentStoppedDTO('critical'));
        $this->pump($loop);
        $this->assertSame(0, $server->startCount, 'a stop invalidates the already checked prefix');
        $this->assertFalse($loop->hasReadyWork(), 'pending agents wait for a real fact');
        $agents->handleAgentStartFailed(new WorkerAgentStartFailedDTO('critical', 'critical', reason: 'refused'));
        $this->assertTrue($loop->hasReadyWork());
        $this->pump($loop);
        $this->assertSame(0, $server->startCount);
        $agents->addAgent('critical', $this->createMock(AgentDaemonInterface::class), 1, false);
        $agents->handleAgentStarted(new WorkerAgentStartedDTO('critical', 'critical'));
        $this->pump($loop);
        $this->assertSame(1, $server->startCount);
        $this->assertFalse($loop->hasReadyWork());
    }

    public function testElectionFactsReevaluateTheGateWithoutResettingItsWait(): void
    {
        $coordinator = $this->cluster('master');
        [$manager, $server] = $this->readyManager();
        $manager->agentStarted = true;
        $loop = $this->bind($manager);
        $loop->tick();
        $waitSince = new ReflectionProperty(DaemonManager::class, 'readinessWaitSince')->getValue($manager);
        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $loop->tick();
        $this->assertSame(0, $server->startCount, 'a candidate still waits for a leader');
        $this->assertSame($waitSince, new ReflectionProperty(DaemonManager::class, 'readinessWaitSince')->getValue($manager));
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'm1'));
        $loop->tick();
        $this->assertSame(1, $server->startCount);
        $this->assertSame(1, $manager->requiredAgentReads);
        $this->assertFalse($loop->hasReadyWork());
    }

    public function testWorkerLossInvalidatesAnAlreadyCheckedAgentPrefix(): void
    {
        Hilos::$cluster = null;
        [$manager, $server] = $this->readyManager();
        $manager->useRealRoster = true;
        $manager->requiredAgents = array_merge(['critical'], array_fill(0, 129, 'supporting'));
        $agents = $manager->getAgentManagerDaemon();
        foreach (['critical' => 1, 'supporting' => 2] as $id => $worker) {
            $agents->addAgent($id, $this->createMock(AgentDaemonInterface::class), $worker, false);
            $agents->handleAgentStarted(new WorkerAgentStartedDTO($id, $id));
        }
        $loop = $this->bind($manager);
        $loop->tick();
        $this->assertTrue($loop->hasReadyWork());
        $agents->forgetAgentsOfWorker(1, false);
        $this->pump($loop);
        $this->assertSame(0, $server->startCount, 'a lost worker invalidates the already checked prefix');
        $this->assertFalse($loop->hasReadyWork());
    }

    public function testRebindingCancelsAnActivatedOldDeadlineAndQueuedGate(): void
    {
        Hilos::$cluster = null;
        [$manager, $server] = $this->readyManager();
        $loop = $this->bind($manager);
        $loop->tick();
        $timer = $this->timer($manager);
        $timer->armAt(hrtime(true) / 1e9 - 1.0);
        $replacement = null;
        $loop->createReadyWork(function () use ($manager, &$replacement): void {
            $replacement = $this->bind($manager);
        })->markReady();
        $loop->tick();
        $manager->agentStarted = true;
        $this->pump($loop);
        $this->assertSame(0, $server->startCount, 'the replaced generation cannot evaluate the gate');
        $this->assertNull($timer->getDeadline());
        $this->pump($replacement);
        $this->assertSame(1, $server->startCount);
    }

    /** @param EventLoop $loop Native loop whose bounded portions are drained without waiting */
    private function pump(EventLoop $loop): void
    {
        for ($portion = 0; $portion < 8; ++$portion) {
            $loop->tick();
        }
    }

    /**
     * @param ReadinessTestManager $manager Master to bind to a real native loop
     * @return EventLoop Same loop the production startup seam uses
     */
    private function bind(ReadinessTestManager $manager): EventLoop
    {
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachReadinessEventLoop')->invoke($manager);
        $this->bindings[] = [$manager, $loop];
        return $loop;
    }

    /**
     * @param ReadinessTestManager $manager Owner of the readiness deadline
     * @return ?EventLoopTimer Currently bound timer, null once the gate closes
     */
    private function timer(ReadinessTestManager $manager): ?EventLoopTimer
    {
        return new ReflectionProperty(DaemonManager::class, 'readinessTimer')->getValue($manager);
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
        putenv('CLUSTER_NODE_NAME=Local node');
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
            hrtime(true) / 1e9 - self::EXPIRED_READINESS_WAIT_SECONDS,
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

    public bool $useRealRoster = false;

    /** @var list<string> Required startup agents for the portioned walk */
    public array $requiredAgents = ['critical'];

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
        return $this->requiredAgents;
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
        return $this->manager->useRealRoster ? parent::isAgentStarted($agentId) : $this->manager->agentStarted;
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

/** An empty worker transport lets the real signal dispatcher consume WORKERS_READY. */
final class ReadinessTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    protected function onStart(): void
    {
    }
}

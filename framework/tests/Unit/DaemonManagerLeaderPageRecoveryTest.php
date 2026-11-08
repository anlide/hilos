<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\NullLeadershipObserver;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Page\DTO\PageResubscribeSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\ProtectedMode\ProtectedModeSyncScheduler;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;
use WeakReference;

/** Exact source-master frames; the live cross-node reproduction lives in cluster/daemon. */
final class DaemonManagerLeaderPageRecoveryTest extends TestCase
{
    private string $previousApp;
    private ?EnvAccessor $previousEnv;
    private ?ClusterContext $previousCluster;
    private LeaderPageRecoveryManager $manager;
    private LeaderPageRecoveryServer $server;
    private ClusterCoordinator $consensus;
    private LeaderPageRecoveryMesh $mesh;
    private EventLoop $loop;

    protected function setUp(): void
    {
        $this->previousApp = Hilos::appClass();
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, LeaderPageRecoveryApp::class);
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=source');
        putenv('CLUSTER_NODE_NAME=Local node');
        putenv('CLUSTER_NODE_ROLE=master');
        putenv('CLUSTER_PEER_ADVERTISE=10.0.0.1:7000');
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        Hilos::$cluster = new ClusterContext();
        $this->mesh = new LeaderPageRecoveryMesh();
        $this->consensus = $this->coordinator();
        Hilos::$cluster->registerLeadership($this->consensus);
        $this->manager = new LeaderPageRecoveryManager();
        $this->server = new LeaderPageRecoveryServer();
        $this->manager->registerServer($this->server);
        $this->loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $this->loop);
        new ReflectionProperty(DaemonManager::class, 'webSocketStarted')->setValue($this->manager, true);
        $this->attach();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $this->detach();
        }
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = null;
        foreach (['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_NAME', 'CLUSTER_NODE_ROLE', 'CLUSTER_PEER_ADVERTISE'] as $key) {
            putenv($key);
        }
    }

    public function testActualEpochChangesCueSlaveSyncWithoutReplacingPageRecovery(): void
    {
        $sync = $this->createMockForIntersectionOfInterfaces([ProtectedModeSwitch::class, ProtectedModeSyncScheduler::class]);
        $sync->expects(self::exactly(2))->method('noteSlaveSyncChanged');
        Hilos::$cluster->registerProtectedMode($sync);
        $this->subscribe('tab');
        $this->tick();
        $this->assertFrame('tab');
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(4, 'first', true));
        $this->tick();
        $this->assertFrame('tab');
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(4, 'first', true));
        $this->tick();
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(4, 'first', false));
        $this->tick();
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testASourceThatStaysFollowerAsksItsExactSocketToSubscribeToTheNewLeader(): void
    {
        $params = ['room' => '42', 'view' => ['active' => true, 'limit' => 2]];
        $this->subscribe('tab', 'leader', $params);
        $this->tick();
        $this->assertFrame('tab', $params);
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(4, 'other', true));
        $this->tick();
        $this->assertFalse($this->consensus->amLeader());
        $this->assertFrame('tab', $params);
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testTheSourceBecomingLeaderAlsoRestoresItsLocalViewers(): void
    {
        $this->subscribe('tab');
        $this->tick();
        $this->assertFrame('tab');
        $this->consensus->triggerDesignatedElection();
        $this->consensus->tick(0.0);
        $this->consensus->onVoteReply(new PeerVoteReplyDTO(4, true, 'first'));
        $this->tick();
        $this->assertTrue($this->consensus->amLeader());
        $this->assertFrame('tab');
    }

    public function testANewTermOfTheSameLeaderIsANewRecoveryEpoch(): void
    {
        $this->subscribe('tab');
        $this->tick();
        $this->assertFrame('tab');
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(4, 'first', true));
        $this->tick();
        $this->assertFrame('tab');
    }

    public function testNoLeaderOrNoQuorumSendsNothingAndTheNextAvailableEpochRecovers(): void
    {
        $this->subscribe('tab');
        $this->consensus->noteNodeOffline('first');
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(3, 'first', true));
        $this->mesh->online = ['source'];
        $this->consensus->tick(0.0);
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->mesh->online = ['source', 'first', 'other'];
        $this->consensus->tick(0.0);
        $this->tick();
        $this->assertFrame('tab');
        $this->mesh->online = ['source'];
        $this->consensus->tick(0.0);
        $this->tick();
        $this->mesh->online = ['source', 'first', 'other'];
        $this->consensus->tick(0.0);
        $this->tick();
        $this->assertFrame('tab');
    }

    public function testTheBoundedWalkSkipsNavigatedAndClosedSocketsAndDoesNotRevisitTheNewPage(): void
    {
        for ($index = 0; $index < 64; $index++) {
            $this->subscribe('tab-' . $index);
        }
        $this->subscribe('navigated');
        $this->subscribe('updated', params: ['room' => '42']);
        $closed = $this->subscribe('closed');
        $this->subscribe('unchanged');
        $this->tick();
        for ($index = 0; $index < 64; $index++) {
            $this->assertFrame('tab-' . $index);
        }
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        Hilos::$sr->subscribeToPage('elsewhere', new WebSocketPageSubscribeSignalDTO('navigated', 'elsewhere'));
        Hilos::$sr->subscribeToPage('picture', new WebSocketPageSubscribeSignalDTO('updated', 'picture', ['room' => '43']));
        $closed->leave();
        $this->tick();
        $this->assertFrame('unchanged');
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testANewEpochReplacesAnUnfinishedWalk(): void
    {
        for ($index = 0; $index < 65; $index++) {
            $this->subscribe('tab-' . $index);
        }
        $this->tick();
        for ($index = 0; $index < 64; $index++) {
            $this->assertFrame('tab-' . $index);
        }
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(4, 'first', true));
        $this->tick();
        for ($index = 0; $index < 64; $index++) {
            $this->assertFrame('tab-' . $index);
        }
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->tick();
        $this->assertFrame('tab-64');
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testSocketsWithoutSubscriptionsAlsoConsumeThePassBudget(): void
    {
        for ($index = 0; $index < 64; $index++) {
            $this->server->attach(new LeaderPageRecoveryClient('empty-' . $index));
        }
        $this->subscribe('viewer');
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->tick();
        $this->assertFrame('viewer');
        $this->subscribe('new-tab');
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testARefusedViewerDoesNotStopTheOtherViewers(): void
    {
        $this->subscribe('bad', params: ['not-a-map']);
        $this->subscribe('good');
        $this->tick();
        $this->assertFrame('good');
        $this->assertCount(1, $this->manager->failures);
        $this->assertStringContainsString('bad', $this->manager->failures[0]);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testNodeAndPolicySubscriptionsAndStandaloneDoNotGetThisTrigger(): void
    {
        $this->subscribe('node-tab', 'node');
        $this->subscribe('policy-tab', 'policy');
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->subscribe('leader-tab');
        Hilos::$cluster = null;
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testASlaveIsNotASourceOfBrowserRecovery(): void
    {
        putenv('CLUSTER_NODE_NAME=Local node');
        putenv('CLUSTER_NODE_ROLE=slave');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership($this->consensus);
        $this->subscribe('tab');
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testNativeContinuationNeedsNoInputAndDoesNotDeliverInsideTheCallback(): void
    {
        for ($index = 0; $index < 65; ++$index) {
            $this->subscribe('tab-' . $index);
        }
        $this->loop->runOnce();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->tick();
        for ($index = 0; $index < 64; ++$index) {
            $this->assertFrame('tab-' . $index);
        }
        $this->assertTrue($this->loop->hasReadyWork());
        $this->subscribe('later');
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(3, 'first', true));
        $this->tick();
        $this->assertFrame('tab-64');
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(3, 'first', false));
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(2, 'other', true));
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(9, 'source', true));
        $this->consensus->tick(0.0);
        $this->assertFalse($this->loop->hasReadyWork());
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testUnavailableEpochCancelsContinuationAndSleepsUntilAnActualFact(): void
    {
        for ($index = 0; $index < 65; ++$index) {
            $this->subscribe('tab-' . $index);
        }
        $this->tick();
        for ($index = 0; $index < 64; ++$index) {
            $this->assertFrame('tab-' . $index);
        }
        $this->mesh->online = ['source'];
        $this->consensus->tick(0.0);
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->consensus->tick(0.0);
        $this->assertFalse($this->loop->hasReadyWork());
        $this->mesh->online = ['source', 'first'];
        $this->consensus->tick(0.0);
        $this->assertTrue($this->loop->hasReadyWork());
        $this->tick();
        for ($index = 0; $index < 64; ++$index) {
            $this->assertFrame('tab-' . $index);
        }
    }

    public function testCoalescedQuorumLossAndReturnRestoresTheSameLeaderAndTerm(): void
    {
        $this->subscribe('tab');
        $this->tick();
        $this->assertFrame('tab');
        $this->mesh->online = ['source'];
        $this->consensus->tick(0.0);
        $this->mesh->online = ['source', 'first'];
        $this->consensus->tick(0.0);
        $this->loop->runOnce();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->tick();
        $this->assertFrame('tab');
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testNativeMembershipEdgesRecoverTheSameLeaderWithoutPeriodicConsensusTicks(): void
    {
        $this->consensus->attachEventLoop($this->loop);
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(3, 'first', true));
        $this->consensus->dispatchReady();
        try {
            $this->subscribe('tab');
            $this->tick();
            $this->assertFrame('tab');
            $revision = $this->consensus->epochRevision();
            $this->mesh->online = ['source'];
            $this->consensus->noteMembershipChanged();
            $this->mesh->online = ['source', 'first'];
            $this->consensus->noteMembershipChanged();
            $this->assertSame($revision + 2, $this->consensus->epochRevision());
            $this->assertSame('first', $this->consensus->leaderId());
            $this->assertSame(3, $this->consensus->term());
            $this->loop->runOnce();
            $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'native callbacks only cue guarded recovery');
            $this->consensus->dispatchReady();
            $this->tick();
            $this->assertFrame('tab');
            $this->assertFalse($this->consensus->hasReadyWork());
            $this->assertFalse($this->loop->hasReadyWork());
        } finally {
            $this->consensus->detachEventLoop();
        }
    }

    public function testRebindKeepsTheUnfinishedCohortAndRejectsEscapedOldCallbacks(): void
    {
        for ($index = 0; $index < 65; ++$index) {
            $this->subscribe('tab-' . $index);
        }
        $this->tick();
        for ($index = 0; $index < 64; ++$index) {
            $this->assertFrame('tab-' . $index);
        }
        $oldWake = new ReflectionProperty(DaemonManager::class, 'leaderPageRecoveryWake')->getValue($this->manager);
        $oldWork = new ReflectionProperty(DaemonManager::class, 'leaderPageRecoveryWork')->getValue($this->manager);
        $this->detach();
        $this->assertTrue($oldWork->isClosed());
        $oldWake();
        $this->assertFalse($this->loop->hasReadyWork());
        $this->consensus->removeEpochWake($oldWake);
        $this->subscribe('later');
        $oldLoop = $this->loop;
        $this->loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $this->loop);
        $this->attach();
        $oldWake();
        $this->assertFalse($oldLoop->hasReadyWork());
        $this->tick();
        $this->assertFrame('tab-64');
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());
        $oldWake();
        $this->consensus->removeEpochWake($oldWake);
        $this->assertFalse($this->loop->hasReadyWork());
        $this->attach();
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(4, 'other', true));
        $this->assertTrue($this->loop->hasReadyWork());
    }

    public function testDetachedMutationAndReplacementOwnerOrRouterStartTheCorrectBasis(): void
    {
        $this->subscribe('tab');
        $this->tick();
        $this->assertFrame('tab');
        $this->detach();
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(4, 'other', true));
        $this->assertFalse($this->loop->hasReadyWork());
        $this->attach();
        $this->tick();
        $this->assertFrame('tab');
        $old = $this->consensus;
        $this->consensus = $this->coordinator();
        Hilos::$cluster->registerLeadership($this->consensus);
        $this->tick();
        $this->assertFrame('tab');
        $old->onHeartbeat(new PeerHeartbeatDTO(5, 'first', true));
        $this->assertFalse($this->loop->hasReadyWork());
        Hilos::$sr = new SignalRouter();
        $this->subscribe('replacement');
        $this->tick();
        $this->assertFrame('replacement');
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testReadinessBindingAndItsDepartureRemainIndependentAfterWebSocketOpening(): void
    {
        $readiness = 0;
        $wake = static function () use (&$readiness): void { ++$readiness; };
        $this->consensus->setReadinessWake($wake);
        $this->attach();
        $this->assertSame($wake, new ReflectionProperty(ClusterCoordinator::class, 'readinessWake')->getValue($this->consensus));
        $this->subscribe('tab');
        $this->tick();
        $this->assertFrame('tab');
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(3, 'other', true));
        $this->assertSame(2, $readiness);
        $this->tick();
        $this->assertFrame('tab');
        new ReflectionProperty(DaemonManager::class, 'readinessCoordinator')->setValue($this->manager, $this->consensus);
        new ReflectionMethod(DaemonManager::class, 'detachReadinessEventLoop')->invoke($this->manager);
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(4, 'other', true));
        $this->assertSame(2, $readiness);
        $this->tick();
        $this->assertFrame('tab');
    }

    public function testDiscardClosesQueuedWorkAndAnEscapedCueDoesNotRetainTheManager(): void
    {
        $this->subscribe('tab');
        $wake = new ReflectionProperty(DaemonManager::class, 'leaderPageRecoveryWake')->getValue($this->manager);
        new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($this->manager);
        $this->assertFalse($this->loop->hasReadyWork());
        $wake();
        $this->consensus->onHeartbeat(new PeerHeartbeatDTO(4, 'other', true));
        $this->assertFalse($this->loop->hasReadyWork());
        $owner = WeakReference::create($this->manager);
        unset($this->manager, $this->server);
        gc_collect_cycles();
        $this->assertNull($owner->get());
        $wake();
    }

    private function tick(): void
    {
        if ($this->loop->hasReadyWork()) {
            $this->loop->runOnce();
        }
        new ReflectionMethod(DaemonManager::class, 'dispatchLeaderPageRecovery')->invoke($this->manager);
    }

    private function attach(): void
    {
        new ReflectionMethod(DaemonManager::class, 'attachLeaderPageRecoveryEventLoop')->invoke($this->manager);
    }

    private function detach(): void
    {
        new ReflectionMethod(DaemonManager::class, 'detachLeaderPageRecoveryEventLoop')->invoke($this->manager);
    }

    private function coordinator(): ClusterCoordinator
    {
        $coordinator = new ClusterCoordinator(
            new ClusterConsensusConfig('source', ['source', 'first', 'other'], 2, 1000, 1000, 100),
            $this->mesh,
            new NullLeadershipObserver(),
        );
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(3, 'first', true));
        $coordinator->tick(0.0);
        $coordinator->noteWebSocketOpen();
        return $coordinator;
    }

    private function subscribe(string $key, string $agent = 'leader', array $params = []): LeaderPageRecoveryClient
    {
        $client = new LeaderPageRecoveryClient($key);
        $this->server->attach($client);
        Hilos::$sr->subscribeToPage('picture', new WebSocketPageSubscribeSignalDTO($key, 'picture', $params));
        Hilos::$sr->bindPageAgent($key, 'picture', $agent, null);
        return $client;
    }

    private function assertFrame(string $key, array $params = []): void
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalConstants::PAGE_RESUBSCRIBE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame($key, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResubscribeSignalData::class, $signal->data->data);
        $this->assertSame('picture', $signal->data->data->page);
        $this->assertSame($params, $signal->data->data->params);
        $this->assertSame(
            json_encode(['page' => 'picture', 'params' => (object) $params]),
            json_encode($signal->data->data->toArray()),
        );
    }
}

abstract class LeaderPageRecoveryApp extends Hilos
{
    public const array AGENTS = [
        'leader' => [AgentRegistryKey::SCOPE => AgentScope::CLUSTER, AgentRegistryKey::PLACEMENT => AgentPlacement::LEADER],
        'node' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        'policy' => [AgentRegistryKey::SCOPE => AgentScope::CLUSTER, AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
    ];
}

final class LeaderPageRecoveryManager extends DaemonManager
{
    /** @var list<string> Contained viewer failures, without journal I/O */
    public array $failures = [];

    /** This unit fixture exercises the recovery pass without booting a daemon. */
    public function __construct()
    {
    }

    /** @return SignalRouter Unused factory; the test installs its own registry */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManagerDaemon No agent manager is needed by this unit fixture
     * @throws LogicException When the test accidentally starts an agent manager
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        throw new LogicException('Not started by this test');
    }

    /** @param string $message Contained recovery failure */
    protected function logException(string $message): void
    {
        $this->failures[] = $message;
    }
}

final class LeaderPageRecoveryServer extends WebSocketServer
{
    /** Keep the socket list without binding a listening socket. */
    public function __construct()
    {
    }

    /** @param WebSocketClient $client Socket to include in the epoch walk */
    public function attach(WebSocketClient $client): void
    {
        $this->addClient($client);
    }

    /** This unit fixture never starts a listening server. */
    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Unused accepted socket
     * @return WebSocketClientInterface No socket is accepted by this fixture
     * @throws LogicException When the test accidentally accepts a client
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new LogicException('No accept in this test');
    }
}

final class LeaderPageRecoveryClient extends WebSocketClient
{
    /** @param string $key Address of the socket represented by this fixture */
    public function __construct(string $key)
    {
        $this->acceptKey = $key;
        $this->socket = new stdClass();
    }

    /** Mark the socket closed while its old subscription still exists. */
    public function leave(): void
    {
        $this->socket = null;
    }

    /**
     * @param array<string, string> $headers Unused handshake headers
     * @param string $acceptKey Unused handshake address
     * @param array<string, string> $cookies Unused handshake cookies
     * @param ?string $clientIp Unused client address
     * @param RequestQueryParams $queryParams Unused handshake query
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
    }
}

final class LeaderPageRecoveryMesh implements ConsensusMesh
{
    /** @var list<string> Live fixture masters */
    public array $online = ['source', 'first', 'other'];

    /** @return list<string> Current live master set */
    public function onlineMasterIds(): array
    {
        return $this->online;
    }

    /** @param PeerDTO $frame Consensus frame with no transport in this fixture */
    public function broadcastToMasters(PeerDTO $frame): void
    {
    }

    /**
     * @param string $nodeId Fixture target
     * @param PeerDTO $frame Consensus frame with no transport in this fixture
     */
    public function sendToMaster(string $nodeId, PeerDTO $frame): void
    {
    }
}

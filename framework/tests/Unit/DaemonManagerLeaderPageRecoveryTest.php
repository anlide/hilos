<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ConsensusInspection;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\Leadership;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Page\DTO\PageResubscribeSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/** Exact source-master frames; the live cross-node reproduction lives in cluster/daemon. */
final class DaemonManagerLeaderPageRecoveryTest extends TestCase
{
    private string $previousApp;
    private ?EnvAccessor $previousEnv;
    private ?ClusterContext $previousCluster;
    private LeaderPageRecoveryManager $manager;
    private LeaderPageRecoveryServer $server;
    private LeaderPageRecoveryConsensus $consensus;

    protected function setUp(): void
    {
        $this->previousApp = Hilos::appClass();
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, LeaderPageRecoveryApp::class);
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=source');
        putenv('CLUSTER_NODE_ROLE=master');
        putenv('CLUSTER_PEER_ADVERTISE=10.0.0.1:7000');
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        Hilos::$cluster = new ClusterContext();
        $this->consensus = new LeaderPageRecoveryConsensus();
        Hilos::$cluster->registerLeadership($this->consensus);
        $this->manager = new LeaderPageRecoveryManager();
        $this->server = new LeaderPageRecoveryServer();
        $this->manager->registerServer($this->server);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = null;
        foreach (['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE', 'CLUSTER_PEER_ADVERTISE'] as $key) {
            putenv($key);
        }
    }

    public function testASourceThatStaysFollowerAsksItsExactSocketToSubscribeToTheNewLeader(): void
    {
        $params = ['room' => '42', 'view' => ['active' => true, 'limit' => 2]];
        $this->subscribe('tab', 'leader', $params);
        $this->tick();
        $this->assertFrame('tab', $params);
        $this->consensus->leader = 'other';
        $this->consensus->epoch++;
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
        $this->consensus->leader = 'source';
        $this->consensus->epoch++;
        $this->tick();
        $this->assertTrue($this->consensus->amLeader());
        $this->assertFrame('tab');
    }

    public function testANewTermOfTheSameLeaderIsANewRecoveryEpoch(): void
    {
        $this->subscribe('tab');
        $this->tick();
        $this->assertFrame('tab');
        $this->consensus->epoch++;
        $this->tick();
        $this->assertFrame('tab');
    }

    public function testNoLeaderOrNoQuorumSendsNothingAndTheNextAvailableEpochRecovers(): void
    {
        $this->subscribe('tab');
        $this->consensus->leader = null;
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->consensus->leader = 'first';
        $this->consensus->quorum = false;
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->consensus->quorum = true;
        $this->tick();
        $this->assertFrame('tab');
        $this->consensus->quorum = false;
        $this->tick();
        $this->consensus->quorum = true;
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
        $this->consensus->epoch++;
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
        putenv('CLUSTER_NODE_ROLE=slave');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership($this->consensus);
        $this->subscribe('tab');
        $this->tick();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    private function tick(): void
    {
        new ReflectionMethod(DaemonManager::class, 'tickLeaderPageRecovery')->invoke($this->manager);
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
        $this->clients[] = $client;
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

final class LeaderPageRecoveryConsensus implements Leadership, ConsensusInspection
{
    public ?string $leader = 'first';
    public int $epoch = 3;
    public bool $quorum = true;

    /** @return bool Whether the scripted election selected the source */
    public function amLeader(): bool
    {
        return $this->leader === 'source';
    }

    /** @return ?string Scripted available leader */
    public function leaderId(): ?string
    {
        return $this->leader;
    }

    /** @return bool Always available on this fixture */
    public function leaderWebSocketOpen(): bool
    {
        return true;
    }

    /** @return bool Scripted availability of a quorum */
    public function hasQuorum(): bool
    {
        return $this->quorum;
    }

    /** @return int Scripted consensus term */
    public function term(): int
    {
        return $this->epoch;
    }

    /** @return ConsensusRole Role of the source in the scripted election */
    public function consensusRole(): ConsensusRole
    {
        return $this->amLeader() ? ConsensusRole::Leader : ConsensusRole::Follower;
    }

    /** @return int Visible masters of the fixture */
    public function onlineMasterCount(): int
    {
        return 3;
    }

    /** @return int Configured fixture masters */
    public function masterSetSize(): int
    {
        return 3;
    }

    /** @return int Majority of the fixture masters */
    public function quorumSize(): int
    {
        return 2;
    }
}

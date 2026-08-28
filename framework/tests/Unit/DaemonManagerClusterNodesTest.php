<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the cluster roster the master publishes into runtime state (HIL-337).
 *
 * The collection exists so a worker can learn that other nodes exist at all - the registry it
 * mirrors lives on the master and is reachable from nowhere else. What is pinned here is that
 * the mirror never disagrees with the registry: it is rebuilt from the snapshot on every
 * membership event, it keeps the row of a node that left instead of dropping it, and it says
 * the same thing on an install with no clustering at all, where it publishes the one node
 * there is. The last one is the case a reader would otherwise need a branch for, which is why
 * it is not left to the consumer.
 *
 * Publishing is also the one thing on the master loop that must never throw: an escaped
 * exception there ends the daemon, so a write the master is not the source for is written
 * down rather than raised.
 */
final class DaemonManagerClusterNodesTest extends TestCase
{
    private const string SELF = 'node-a';

    private const string PEER = 'node-b';

    private const string PEER_ADDRESS = '10.0.0.2:7000';

    private ?RtContext $previousRt = null;

    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->previousRt = Hilos::$rt;
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;

        Hilos::$env = new EnvAccessor();
        Hilos::$rt = new ClusterNodesTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosClusterNode::RT_COLLECTION);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv('CLUSTER_ENABLED');
        putenv('CLUSTER_NODE_ID');
        putenv('CLUSTER_NODE_ROLE');
        putenv('CLUSTER_NODE_CAPABILITIES');
        putenv('CLUSTER_PEER_ADVERTISE');

        parent::tearDown();
    }

    public function testWithoutClusteringTheMasterPublishesItselfAsTheOnlyRow(): void
    {
        $this->startMaster(new ClusterNodesTestManager());

        $this->assertCount(1, Hilos::$rt->hilosClusterNodes, 'A node with no cluster is one node, and it says so');

        $row = Hilos::$rt->hilosClusterNodes[StateHilosClusterNode::STANDALONE_NODE_ID];
        $this->assertNotNull($row, 'The standalone row is keyed by the empty id, since there is no identity to name it');
        $this->assertSame(NodeRole::Master->value, $row->role, 'A node on its own is its own master');
        $this->assertTrue($row->online, 'The node publishing the row is by definition up');
        $this->assertSame([], $row->capabilities);
        $this->assertNull($row->address, 'There is no mesh to advertise an address to');
    }

    public function testWithoutClusteringTheRowIsPublishedOnlyOnce(): void
    {
        $manager = new ClusterNodesTestManager();
        $this->startMaster($manager);
        $published = Hilos::$rt->hilosClusterNodes[StateHilosClusterNode::STANDALONE_NODE_ID]?->lastSeen;
        $this->recordingRouter()->rtFrames = [];

        $this->publish($manager);

        $this->assertSame([], $this->recordingRouter()->rtFrames, 'Nothing happened, so nothing may go on the wire');
        $this->assertSame(
            $published,
            Hilos::$rt->hilosClusterNodes[StateHilosClusterNode::STANDALONE_NODE_ID]?->lastSeen,
            'Re-stamping the row on every call would make an unchanging node look like news',
        );
    }

    public function testAJoinPublishesTheJoinedNodeWithEveryFieldOfItsRecord(): void
    {
        $manager = new ClusterNodesTestManager();
        $this->enableCluster();
        $this->startMaster($manager);
        $peer = NodeIdentity::of(self::PEER, NodeRole::Slave, ['gpu'], PeerAddress::fromString(self::PEER_ADDRESS));
        $seenAt = microtime(true);
        Hilos::$cluster?->registry()->merge($peer, true, $seenAt);

        $manager->onNodeJoined(ClusterNode::fromIdentity($peer, true, $seenAt));

        $row = Hilos::$rt->hilosClusterNodes[self::PEER];
        $this->assertNotNull($row, 'A node the registry knows is a node the workers may read');
        $this->assertSame(NodeRole::Slave->value, $row->role);
        $this->assertSame(['gpu'], $row->capabilities);
        $this->assertSame(self::PEER_ADDRESS, $row->address);
        $this->assertTrue($row->online);
        $this->assertSame($seenAt, $row->lastSeen);
    }

    public function testAPublicationWithoutAMembershipChangeQueuesNothing(): void
    {
        $manager = new ClusterNodesTestManager();
        $this->enableCluster();
        $this->startMaster($manager);
        $this->recordingRouter()->rtFrames = [];

        $this->publish($manager);

        $this->assertSame(
            [],
            $this->recordingRouter()->rtFrames,
            'The membership version has not moved, so there is nothing to walk and nothing to send',
        );
    }

    public function testAJoinLeavesTheNodesThatDidNotMoveOffTheWire(): void
    {
        $manager = new ClusterNodesTestManager();
        $this->enableCluster();
        $this->startMaster($manager);
        $peer = NodeIdentity::of(self::PEER, NodeRole::Slave, [], null);
        $seenAt = microtime(true);
        Hilos::$cluster?->registry()->merge($peer, true, $seenAt);
        $this->recordingRouter()->rtFrames = [];

        $manager->onNodeJoined(ClusterNode::fromIdentity($peer, true, $seenAt));

        // The publication walks the whole snapshot, so this node is re-published along with the
        // one that joined - and has to cost nothing, or every node in the mesh would be framed
        // again on each membership event. That is what makes the row's own diff, rather than the
        // caller's word for what changed, the only workable form here.
        $framedNodes = array_map(static fn(RtSyncSignalDataInterface $frame): string => $frame->stateId, $this->recordingRouter()->rtFrames);
        $this->assertNotContains(self::SELF, $framedNodes, 'A row nobody touched has no news in it');
    }

    public function testANodeThatLeftKeepsItsRowAndTurnsOffline(): void
    {
        $manager = new ClusterNodesTestManager();
        $this->enableCluster();
        $this->startMaster($manager);
        $peer = NodeIdentity::of(self::PEER, NodeRole::Slave, [], null);
        Hilos::$cluster?->registry()->merge($peer, true, microtime(true));
        $manager->onNodeJoined(ClusterNode::fromIdentity($peer, true, microtime(true)));
        $joinedAt = Hilos::$rt->hilosClusterNodes[self::PEER]?->lastSeen;
        $leftAt = microtime(true);
        Hilos::$cluster?->registry()->markOffline(self::PEER, $leftAt);

        $manager->onNodeLeft(ClusterNode::fromIdentity($peer, false, $leftAt));

        $row = Hilos::$rt->hilosClusterNodes[self::PEER];
        $this->assertNotNull($row, 'A node that was here and fell over is data, not the absence of it');
        $this->assertFalse($row->online);
        $this->assertGreaterThan((float)$joinedAt, $row->lastSeen, 'The row carries when the node was last seen, not when it joined');
    }

    public function testAReloadOfTheLocalRecordReachesTheRow(): void
    {
        $manager = new ClusterNodesTestManager();
        $this->enableCluster();
        $this->startMaster($manager);
        // The daemon registers itself at start; the reload is only announced to whoever asked.
        Hilos::$cluster?->registerMembershipObserver($manager);
        putenv('CLUSTER_NODE_CAPABILITIES=gpu');

        Hilos::$cluster?->reload();

        $this->assertSame(
            ['gpu'],
            Hilos::$rt->hilosClusterNodes[self::SELF]?->capabilities,
            'A reload is the one membership change nobody is told about, so the mirror has to hear it here',
        );
    }

    public function testAPublicationWithoutTheWriteRightIsWrittenDownRatherThanThrown(): void
    {
        $manager = new ClusterNodesTestManager();
        // What a master looks like when the registration never happened or was revoked. The guard
        // refuses the write; the daemon loop must survive the refusal.
        RtTruthSourceRegistry::unregisterDaemon(StateHilosClusterNode::RT_COLLECTION);

        $this->publish($manager);

        $this->assertCount(0, Hilos::$rt->hilosClusterNodes, 'The refused write leaves nothing behind, and nothing escapes');
    }

    /**
     * Puts the environment of a node started as part of a cluster in place.
     */
    private function enableCluster(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=' . self::SELF);
        putenv('CLUSTER_NODE_ROLE=' . NodeRole::Master->value);
        putenv('CLUSTER_PEER_ADVERTISE=10.0.0.1:7000');
        Hilos::$cluster = new ClusterContext();
    }

    /**
     * Runs the start-time step: the master takes the write right and publishes what it sees.
     *
     * @param DaemonManager $manager Manager to start the roster on
     */
    private function startMaster(DaemonManager $manager): void
    {
        new ReflectionMethod(DaemonManager::class, 'registerClusterNodesTruthSource')->invoke($manager);
    }

    /**
     * @param DaemonManager $manager Manager to run one publication on
     */
    private function publish(DaemonManager $manager): void
    {
        new ReflectionMethod(DaemonManager::class, 'publishClusterNodes')->invoke($manager);
    }

    /**
     * @return ClusterNodesRecordingSignalRouter Router recording the runtime frames this node queued
     */
    private function recordingRouter(): ClusterNodesRecordingSignalRouter
    {
        /** @var ClusterNodesRecordingSignalRouter $router */
        $router = Hilos::$sr;

        return $router;
    }
}

/**
 * Signal router that remembers the runtime frames queued through it.
 */
final class ClusterNodesRecordingSignalRouter extends SignalRouter
{
    /** @var list<RtSyncSignalDataInterface> Runtime frames queued since the last time this was emptied */
    public array $rtFrames = [];

    /**
     * @param string $signalName Runtime sync signal name
     * @param RtSyncSignalDataInterface $signalData Frame payload
     */
    public function queueRtSyncSignal(string $signalName, RtSyncSignalDataInterface $signalData): void
    {
        $this->rtFrames[] = $signalData;

        parent::queueRtSyncSignal($signalName, $signalData);
    }
}

/**
 * Runtime context carrying the framework's own state and nothing of a project's.
 */
final class ClusterNodesTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

/**
 * Daemon manager with the factory wiring the base needs and nothing else.
 */
final class ClusterNodesTestManager extends DaemonManager
{
    /**
     * The manager binds the router the whole process then uses, so the recording one has to come
     * from here rather than be planted beside it - {@see DaemonManager::__construct()} would
     * replace anything planted.
     *
     * @return SignalRouter Router recording the runtime frames this node queues
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ClusterNodesRecordingSignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ClusterNodesTestAgentManagerDaemon();
    }
}

/**
 * Agent manager that is never asked for a daemon in these cases.
 */
final class ClusterNodesTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; these cases start no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

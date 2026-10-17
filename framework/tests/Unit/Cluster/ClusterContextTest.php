<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster;

use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\Consensus\ConsensusInspection;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Cluster\Exception\PlacementCapabilityException;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\LocalNodeAnnouncer;
use Hilos\Cluster\MembershipObserver;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeLifecycleState;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\ConnectionPolicy;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\FullMeshConnectionPolicy;
use Hilos\Cluster\Placement\BestFitPlacementPolicy;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementCandidate;
use Hilos\Cluster\Placement\PlacementPolicy;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRefusal;
use Hilos\Cluster\Placement\PlacementSnapshot;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\AdminViewModeRuntime as StateAdminViewModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementExecutor;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementMesh;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the cluster facade context: mode gating and identity access (HIL-177), and the
 * inspect reply, why placed work does not run included (HIL-447).
 */
final class ClusterContextTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        foreach (['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE', 'CLUSTER_NODE_CAPABILITIES'] as $key) {
            putenv($key);
        }
    }

    public function testDisabledByDefault(): void
    {
        $this->assertFalse(new ClusterContext()->isEnabled());
    }

    public function testEnabledWhenFlagSet(): void
    {
        putenv('CLUSTER_ENABLED=true');

        $this->assertTrue(new ClusterContext()->isEnabled());
    }

    public function testIdentityThrowsWhenDisabled(): void
    {
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        $this->expectException(ClusterDisabledException::class);

        new ClusterContext()->identity();
    }

    public function testIdentityResolvedWhenEnabled(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        $this->assertSame('node-a', new ClusterContext()->identity()->nodeId);
    }

    public function testLocalNodeIdUsesConfiguredStandaloneId(): void
    {
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        $this->assertSame('node-a', new ClusterContext()->localNodeId());
    }

    public function testLocalNodeIdDefaultsToStandalone(): void
    {
        $this->assertSame('standalone', new ClusterContext()->localNodeId());
    }

    public function testLocalNodeIdIsThisNodesIdentity(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        $this->assertSame('node-a', new ClusterContext()->localNodeId());
    }

    public function testIdentityIsMemoized(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        $context = new ClusterContext();

        $this->assertSame($context->identity(), $context->identity());
    }

    public function testSnapshotWhenDisabledHasNoNodes(): void
    {
        $snapshot = new ClusterContext()->snapshot();

        $this->assertFalse($snapshot[ClusterCommandConstants::FIELD_ENABLED]);
        $this->assertSame([], $snapshot[ClusterCommandConstants::FIELD_NODES]);
    }

    public function testSnapshotWhenEnabledCarriesTheLocalNode(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        putenv('CLUSTER_NODE_CAPABILITIES=gpu-local');

        $snapshot = new ClusterContext()->snapshot();

        $this->assertTrue($snapshot[ClusterCommandConstants::FIELD_ENABLED]);
        $this->assertSame(
            [[
                ClusterCommandConstants::FIELD_NODE_ID => 'node-a',
                ClusterCommandConstants::FIELD_NODE_ROLE => 'master',
                ClusterCommandConstants::FIELD_NODE_CAPABILITIES => ['gpu-local'],
                ClusterCommandConstants::FIELD_NODE_ONLINE => true,
            ]],
            $snapshot[ClusterCommandConstants::FIELD_NODES],
        );
    }

    public function testReloadThrowsWhenDisabled(): void
    {
        $this->expectException(ClusterDisabledException::class);

        new ClusterContext()->reload();
    }

    public function testReloadRebuildsLocalNodeFromChangedConfig(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        putenv('CLUSTER_NODE_CAPABILITIES=gpu-local');

        $context = new ClusterContext();
        // Seed the registry with the original identity, as the daemon does at start.
        $context->snapshot();

        putenv('CLUSTER_NODE_CAPABILITIES=gpu-remote,fast-disk');
        $changed = $context->reload();

        $this->assertTrue($changed);
        $this->assertSame(
            ['gpu-remote', 'fast-disk'],
            $context->snapshot()[ClusterCommandConstants::FIELD_NODES][0][ClusterCommandConstants::FIELD_NODE_CAPABILITIES],
        );
    }

    public function testReloadReturnsFalseWhenConfigUnchanged(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        putenv('CLUSTER_NODE_CAPABILITIES=gpu-local');

        $context = new ClusterContext();
        $context->snapshot();

        $this->assertFalse($context->reload());
    }

    public function testReloadAnnouncesOnlyWhenTheLocalNodeChanged(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        putenv('CLUSTER_NODE_CAPABILITIES=gpu-local');

        $announcer = new class implements LocalNodeAnnouncer {
            public int $announced = 0;

            public function announceLocalNode(): void
            {
                $this->announced++;
            }
        };

        $context = new ClusterContext();
        $context->snapshot();
        $context->registerLocalAnnouncer($announcer);

        $context->reload();
        $this->assertSame(0, $announcer->announced);

        putenv('CLUSTER_NODE_ROLE=slave');
        $context->reload();
        $this->assertSame(1, $announcer->announced);
    }

    public function testLeadershipIsStandaloneWhenDisabled(): void
    {
        $leadership = new ClusterContext()->leadership();

        $this->assertInstanceOf(StandaloneLeadership::class, $leadership);
        $this->assertTrue($leadership->amLeader());
        $this->assertTrue($leadership->hasQuorum());
        $this->assertNull($leadership->leaderId());
    }

    public function testLeadershipIsPendingWhenEnabled(): void
    {
        putenv('CLUSTER_ENABLED=true');

        $leadership = new ClusterContext()->leadership();

        $this->assertInstanceOf(PendingLeadership::class, $leadership);
        $this->assertFalse($leadership->amLeader());
        $this->assertFalse($leadership->hasQuorum());
        $this->assertNull($leadership->leaderId());
    }

    public function testLeadershipIsMemoized(): void
    {
        $context = new ClusterContext();

        $this->assertSame($context->leadership(), $context->leadership());
    }

    public function testLifecycleStateIsStandaloneWhenDisabled(): void
    {
        $this->assertSame(NodeLifecycleState::Standalone, new ClusterContext()->lifecycleState());
    }

    public function testLifecycleStateIsSlaveForAClusteredSlave(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=slave');

        $this->assertSame(NodeLifecycleState::Slave, new ClusterContext()->lifecycleState());
    }

    public function testLifecycleStateIsMasterNoQuorumForAClusteredMaster(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        // In this slice PendingLeadership reports no quorum, so a master is dormant.
        $this->assertSame(NodeLifecycleState::MasterNoQuorum, new ClusterContext()->lifecycleState());
    }

    public function testMembershipTransitionsReachTheRegisteredObserver(): void
    {
        $observer = new class implements MembershipObserver {
            /** @var list<string> Node ids reported joined */
            public array $joined = [];

            /** @var list<string> Node ids reported left */
            public array $left = [];

            public function onNodeJoined(ClusterNode $node): void
            {
                $this->joined[] = $node->nodeId;
            }

            public function onNodeLeft(ClusterNode $node): void
            {
                $this->left[] = $node->nodeId;
            }
        };

        $context = new ClusterContext();
        $context->registerMembershipObserver($observer);

        $node = ClusterNode::fromIdentity(NodeIdentity::of('node-b', NodeRole::Master, []), true, 100.0);
        $context->notifyNodeJoined($node);
        $context->notifyNodeLeft($node->asOffline(200.0));

        $this->assertSame(['node-b'], $observer->joined);
        $this->assertSame(['node-b'], $observer->left);
    }

    public function testMembershipNotificationsAreSafeWithoutAnObserver(): void
    {
        $node = ClusterNode::fromIdentity(NodeIdentity::of('node-b', NodeRole::Master, []), true, 100.0);

        $context = new ClusterContext();
        $context->notifyNodeJoined($node);
        $context->notifyNodeLeft($node);

        $this->expectNotToPerformAssertions();
    }

    public function testInspectWhenDisabledReportsOnlyDisabled(): void
    {
        $this->assertSame(
            [ClusterCommandConstants::FIELD_ENABLED => false],
            new ClusterContext()->inspect(),
        );
    }

    public function testInspectWhenEnabledReportsMembershipAndAPendingConsensusView(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        putenv('CLUSTER_NODE_CAPABILITIES=gpu-local');

        $inspection = new ClusterContext()->inspect();

        $this->assertTrue($inspection[ClusterCommandConstants::FIELD_ENABLED]);
        $this->assertSame('node-a', $inspection[ClusterCommandConstants::FIELD_LOCAL_NODE_ID]);
        // A clustered master before the coordinator lands is dormant with no consensus values.
        $this->assertSame('MasterNoQuorum', $inspection[ClusterCommandConstants::FIELD_LIFECYCLE_STATE]);
        $this->assertNull($inspection[ClusterCommandConstants::FIELD_LEADER_ID]);
        $this->assertNull($inspection[ClusterCommandConstants::FIELD_TERM]);
        $this->assertNull($inspection[ClusterCommandConstants::FIELD_CONSENSUS_ROLE]);
        $this->assertFalse($inspection[ClusterCommandConstants::FIELD_HAS_QUORUM]);
        $this->assertSame([], $inspection[ClusterCommandConstants::FIELD_PLACEMENTS]);
        $this->assertNull($inspection[ClusterCommandConstants::FIELD_PLACEMENT_SNAPSHOT]);
        $this->assertSame([], $inspection[ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS_SNAPSHOT]);
        $this->assertFalse($inspection[ClusterCommandConstants::FIELD_ADMIN_VIEW_MODE]);

        $nodes = $inspection[ClusterCommandConstants::FIELD_NODES];
        $this->assertCount(1, $nodes);
        $this->assertSame('node-a', $nodes[0][ClusterCommandConstants::FIELD_NODE_ID]);
        $this->assertSame(['gpu-local'], $nodes[0][ClusterCommandConstants::FIELD_NODE_CAPABILITIES]);
        $this->assertTrue($nodes[0][ClusterCommandConstants::FIELD_NODE_ONLINE]);
        $this->assertArrayHasKey(ClusterCommandConstants::FIELD_NODE_LAST_SEEN, $nodes[0]);
    }

    public function testInspectReportsTheAdminViewModeOfThisNode(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        $previousRt = Hilos::$rt;
        $previousSignalRouter = Hilos::$sr;
        try {
            Hilos::$sr = new SignalRouter();
            Hilos::$rt = new ClusterContextTestRtContext();
            Hilos::$rt->mountFeatureRuntime([]);
            RtTruthSourceRegistry::registerDaemon(StateAdminViewModeRuntime::RT_ITEM);
            Hilos::$rt->hilosAdminViewModeRuntime->actions->set(true);

            $this->assertTrue((new ClusterContext())->inspect()[ClusterCommandConstants::FIELD_ADMIN_VIEW_MODE]);
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(StateAdminViewModeRuntime::RT_ITEM);
            Hilos::$rt = $previousRt;
            Hilos::$sr = $previousSignalRouter;
        }
    }

    public function testInspectReportsConsensusTermAndRoleFromARegisteredCoordinator(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');

        $leadership = new class implements Leadership, ConsensusInspection {
            public function amLeader(): bool
            {
                return true;
            }

            public function leaderId(): ?string
            {
                return 'node-a';
            }

            /** @return bool This test leader has no remote leader to follow */
            public function leaderWebSocketOpen(): bool
            {
                return false;
            }

            public function hasQuorum(): bool
            {
                return true;
            }

            public function term(): int
            {
                return 7;
            }

            public function consensusRole(): ConsensusRole
            {
                return ConsensusRole::Leader;
            }

            public function onlineMasterCount(): int
            {
                return 1;
            }

            public function masterSetSize(): int
            {
                return 1;
            }

            public function quorumSize(): int
            {
                return 1;
            }
        };

        $context = new ClusterContext();
        $context->registerLeadership($leadership);

        $inspection = $context->inspect();

        $this->assertSame('MasterLeader', $inspection[ClusterCommandConstants::FIELD_LIFECYCLE_STATE]);
        $this->assertSame('node-a', $inspection[ClusterCommandConstants::FIELD_LEADER_ID]);
        $this->assertSame(7, $inspection[ClusterCommandConstants::FIELD_TERM]);
        $this->assertSame('leader', $inspection[ClusterCommandConstants::FIELD_CONSENSUS_ROLE]);
        $this->assertTrue($inspection[ClusterCommandConstants::FIELD_HAS_QUORUM]);
    }

    /**
     * A failed record says why in its row, a running one says nothing, and every other field of
     * the row is what it was (HIL-447).
     */
    public function testInspectCarriesTheReasonAndRefusalsBesideTheRecord(): void
    {
        $this->enableClusterNode();
        [$context, $placement] = $this->contextWithALeader();
        $refusal = PlacementRefusal::insufficientCapacity('node-b', ['slots' => ['cost' => 1.0, 'free' => 0.0]]);
        $placement->onPlacementReport('node-c', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '1')]));
        $placement->placeAgentOnNode('render', '9', 'node-b');
        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::failed('render', '9', 'node-b is full', [$refusal]));

        $rows = [];
        foreach ($context->inspect()[ClusterCommandConstants::FIELD_PLACEMENTS] as $row) {
            $rows[$row[ClusterCommandConstants::FIELD_PLACEMENT_AGENT_ID]] = $row;
        }

        $this->assertSame([
            ClusterCommandConstants::FIELD_PLACEMENT_AGENT_TYPE => 'render',
            ClusterCommandConstants::FIELD_PLACEMENT_AGENT_INDEX => '1',
            ClusterCommandConstants::FIELD_PLACEMENT_AGENT_ID => 'render:1',
            ClusterCommandConstants::FIELD_NODE_ID => 'node-c',
            ClusterCommandConstants::FIELD_PLACEMENT_STATE => 'started',
            ClusterCommandConstants::FIELD_PLACEMENT_REASON => null,
            ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS => [],
        ], $rows['render:1']);
        $this->assertSame(
            '{"agentType":"render","agentIndex":"9","agentId":"render:9","nodeId":"node-b","state":"failed",'
            . '"reason":"node-b is full","refusals":[{"nodeId":"node-b","reasonCode":"insufficient_capacity",'
            . '"missingCapabilities":[],"shortfalls":{"slots":{"cost":1,"free":0}}}]}',
            json_encode($rows['render:9']),
        );
    }

    /** Inspection carries a literal null host alongside the unchanged diagnosis fields. */
    public function testInspectPreservesNullNodesAndTheirFailureDiagnosis(): void
    {
        $this->enableClusterNode();
        [$context, $placement] = $this->contextWithALeader();
        $placement->registry()->put(new PlacementRecord(
            'render', 'failed', null, PlacementState::Failed, 'no nodes', [PlacementRefusal::noOnlineNodes()],
        ));
        $placement->registry()->put(new PlacementRecord(
            'render', 'waiting', null, PlacementState::Unplaced, 'no room', [PlacementRefusal::noDeclaredCapacity('node-b')],
        ));
        $placement->registry()->put(new PlacementRecord('render', 'stopped', null, PlacementState::Stopped));
        $placement->registry()->put(new PlacementRecord('render', 'stopping', 'node-b', PlacementState::Stopped));

        $this->assertSame(
            '[{"agentType":"render","agentIndex":"failed","agentId":"render:failed","nodeId":null,"state":"failed",'
            . '"reason":"no nodes","refusals":[{"nodeId":null,"reasonCode":"no_online_nodes",'
            . '"missingCapabilities":[],"shortfalls":{}}]},'
            . '{"agentType":"render","agentIndex":"waiting","agentId":"render:waiting","nodeId":null,"state":"unplaced",'
            . '"reason":"no room","refusals":[{"nodeId":"node-b","reasonCode":"no_declared_capacity",'
            . '"missingCapabilities":[],"shortfalls":{}}]}]',
            json_encode($context->inspect()[ClusterCommandConstants::FIELD_PLACEMENTS]),
        );
    }

    /**
     * New work no node takes has one Unplaced record with no host, beside the leader's snapshot
     * carrying every node's refusal in wire form (HIL-447, HIL-446).
     */
    public function testInspectListsAWaitingRecordAndItsLastRefusal(): void
    {
        $this->enableClusterNode();
        [$context, $placement] = $this->contextWithALeader(nodeB: ['worker']);
        $placement->onPlacementReport('node-c', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '1')]));

        $this->assertNull($placement->placeAgentOnBestNode('render', '9'));
        $inspection = $context->inspect();

        $this->assertSame(
            ['render:1', 'render:9'],
            array_column($inspection[ClusterCommandConstants::FIELD_PLACEMENTS], ClusterCommandConstants::FIELD_PLACEMENT_AGENT_ID),
            'The waiting agent has exactly one row beside the hosted agent',
        );
        $waiting = $inspection[ClusterCommandConstants::FIELD_PLACEMENTS][1];
        $this->assertNull($waiting[ClusterCommandConstants::FIELD_NODE_ID]);
        $this->assertSame('unplaced', $waiting[ClusterCommandConstants::FIELD_PLACEMENT_STATE]);
        $this->assertSame('no capable and online node fits the agent', $waiting[ClusterCommandConstants::FIELD_PLACEMENT_REASON]);
        $this->assertSame(
            json_encode(
                $inspection[ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS_SNAPSHOT][0][ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS],
            ),
            json_encode($waiting[ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS]),
        );
        $this->assertSame(
            '[{"agentType":"render","agentIndex":"9","agentId":"render:9",'
            . '"reason":"no capable and online node fits the agent","refusals":['
            . '{"nodeId":"node-a","reasonCode":"missing_capabilities","missingCapabilities":["worker"],"shortfalls":{}},'
            . '{"nodeId":"node-b","reasonCode":"no_declared_capacity","missingCapabilities":[],"shortfalls":{}},'
            . '{"nodeId":"node-c","reasonCode":"insufficient_capacity","missingCapabilities":[],'
            . '"shortfalls":{"slots":{"cost":1,"free":0}}}]}]',
            json_encode($inspection[ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS_SNAPSHOT]),
        );
    }

    /**
     * A refused move keeps the agent's record running and its row clean, while the snapshot shows
     * the attempt (HIL-447).
     */
    public function testInspectShowsARefusedMoveBesideTheRecordThatStillRuns(): void
    {
        $this->enableClusterNode();
        [$context, $placement] = $this->contextWithALeader(nodeB: ['worker']);
        $placement->onPlacementReport('node-c', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        try {
            $placement->placeAgentOnNode('render', '9', 'node-b');
            $this->fail('node-b declares no capacity');
        } catch (PlacementCapabilityException) {
            // expected
        }
        $inspection = $context->inspect();

        $row = $inspection[ClusterCommandConstants::FIELD_PLACEMENTS][0];
        $this->assertSame('node-c', $row[ClusterCommandConstants::FIELD_NODE_ID]);
        $this->assertSame('started', $row[ClusterCommandConstants::FIELD_PLACEMENT_STATE]);
        $this->assertNull($row[ClusterCommandConstants::FIELD_PLACEMENT_REASON]);
        $this->assertSame([], $row[ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS]);

        $snapshots = $inspection[ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS_SNAPSHOT];
        $this->assertCount(1, $snapshots);
        $this->assertSame('render:9', $snapshots[0][ClusterCommandConstants::FIELD_PLACEMENT_AGENT_ID]);
        $this->assertSame(
            'no_declared_capacity',
            $snapshots[0][ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS][0][PlacementRefusal::FIELD_REASON_CODE],
        );
    }

    public function testInspectOfANodeThatDoesNotLeadListsNoRefusals(): void
    {
        $this->enableClusterNode();
        [$context, $placement] = $this->contextWithALeader(nodeB: ['worker']);
        $placement->onPlacementReport('node-c', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '1')]));
        $this->assertNull($placement->placeAgentOnBestNode('render', '9'));
        $this->assertCount(1, $context->inspect()[ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS_SNAPSHOT]);

        $placement->onLostLeadership();

        $this->assertSame([], $context->inspect()[ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS_SNAPSHOT]);
    }

    public function testInspectIncludesTheLeadersCompleteSnapshotBesideItsWorkingPlacements(): void
    {
        $this->enableClusterNode();
        [$context, $placement] = $this->contextWithALeader();
        $placement->registry()->put(new PlacementRecord('render', 'waiting', null, PlacementState::Failed, 'no room'));
        $placement->registry()->put(new PlacementRecord('render', 'canceled', null, PlacementState::Stopped));
        $inspection = $context->inspect();
        $snapshot = $inspection[ClusterCommandConstants::FIELD_PLACEMENT_SNAPSHOT];
        self::assertSame($placement->placementSnapshot()?->toArray(), $snapshot);
        self::assertSame(['leaderNodeId', 'term', 'revision', 'records'], array_keys($snapshot));
        self::assertCount(2, $snapshot[PlacementSnapshot::FIELD_RECORDS]);
        self::assertSame('stopped', $snapshot[PlacementSnapshot::FIELD_RECORDS][1][PlacementRecord::FIELD_STATE]);
        self::assertCount(1, $inspection[ClusterCommandConstants::FIELD_PLACEMENTS]);
        self::assertNull($inspection[ClusterCommandConstants::FIELD_PLACEMENTS][0][ClusterCommandConstants::FIELD_NODE_ID]);
        self::assertSame($inspection, $context->inspect(), 'Inspection consumes no state');
    }

    public function testInspectRetainsTheFollowersMirrorWithoutACurrentLeader(): void
    {
        $this->enableClusterNode();
        $previousCluster = Hilos::$cluster;
        try {
            Hilos::$cluster = $context = new ClusterContext();
            $placement = new ClusterPlacement('node-a', new FakePlacementMesh([]), new FakePlacementExecutor());
            $context->registerPlacement($placement);
            $placement->onPlacementQuery('leader');
            $snapshot = new PlacementSnapshot('leader', 7, 19, [
                new PlacementRecord('render', 'waiting', null, PlacementState::Unplaced, 'no room'),
                new PlacementRecord('render', 'canceled', null, PlacementState::Stopped),
            ]);
            $placement->onPlacementView('leader', new PeerPlacementViewDTO('leader', [], $snapshot));
            $before = $context->inspect();
            $placement->onPlacementRelease('leader', 1000.0);
            $after = $context->inspect();
            self::assertNull($after[ClusterCommandConstants::FIELD_LEADER_ID]);
            self::assertSame($snapshot->toArray(), $before[ClusterCommandConstants::FIELD_PLACEMENT_SNAPSHOT]);
            self::assertSame($before, $after);
            self::assertSame($after, $context->inspect());
            self::assertSame([], $after[ClusterCommandConstants::FIELD_PLACEMENTS]);
            self::assertSame([], $after[ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS_SNAPSHOT]);
        } finally {
            Hilos::$cluster = $previousCluster;
        }
    }

    /**
     * Turns cluster mode on for this node, the way the inspect cases need it.
     */
    private function enableClusterNode(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
    }

    /**
     * Builds a context whose placement coordinator leads a settled term over node-a (itself, with
     * no tags at all), node-b and node-c (one slot), all online and hosting nothing yet.
     *
     * @param list<string> $nodeB Capability tags node-b advertises
     * @return array{0: ClusterContext, 1: ClusterPlacement} Context and the coordinator it inspects
     */
    private function contextWithALeader(array $nodeB = ['worker', 'slots=4']): array
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => $nodeB, 'node-c' => ['worker', 'slots=1']],
            linked: ['node-b', 'node-c'],
            online: ['node-a', 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement('node-a', $mesh, new FakePlacementExecutor(['worker']));
        $placement->onBecameLeader(1000.0);
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([]));
        $placement->onPlacementReport('node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $context = new ClusterContext();
        $context->registerPlacement($placement);

        return [$context, $placement];
    }

    public function testPlacementPolicyDefaultsToBestFitWhenNoneIsRegistered(): void
    {
        $this->assertInstanceOf(BestFitPlacementPolicy::class, new ClusterContext()->placementPolicy());
    }

    public function testConnectionPolicyDefaultsToFullMeshWhenNoneIsRegistered(): void
    {
        $this->assertInstanceOf(FullMeshConnectionPolicy::class, new ClusterContext()->connectionPolicy());
    }

    public function testRegisteredPlacementPolicyIsHandedBack(): void
    {
        $policy = $this->placementPolicyPicking('node-b');

        $context = new ClusterContext();
        $context->registerPlacementPolicy($policy);

        $this->assertSame($policy, $context->placementPolicy());
    }

    public function testRegisteredConnectionPolicyIsHandedBack(): void
    {
        $policy = $this->connectionPolicyAnswering(false);

        $context = new ClusterContext();
        $context->registerConnectionPolicy($policy);

        $this->assertSame($policy, $context->connectionPolicy());
    }

    public function testSecondPlacementPolicyBeforeTheFirstReadWins(): void
    {
        $second = $this->placementPolicyPicking('node-c');

        $context = new ClusterContext();
        $context->registerPlacementPolicy($this->placementPolicyPicking('node-b'));
        $context->registerPlacementPolicy($second);

        $this->assertSame($second, $context->placementPolicy());
    }

    public function testSecondConnectionPolicyBeforeTheFirstReadWins(): void
    {
        $second = $this->connectionPolicyAnswering(true);

        $context = new ClusterContext();
        $context->registerConnectionPolicy($this->connectionPolicyAnswering(false));
        $context->registerConnectionPolicy($second);

        $this->assertSame($second, $context->connectionPolicy());
    }

    public function testPlacementPolicyRegisteredAfterTheFirstReadIsRefused(): void
    {
        $context = new ClusterContext();
        $context->placementPolicy();

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage('Cluster placement policy was registered after the transport already took it');

        $context->registerPlacementPolicy($this->placementPolicyPicking('node-b'));
    }

    public function testConnectionPolicyRegisteredAfterTheFirstReadIsRefused(): void
    {
        $context = new ClusterContext();
        $context->connectionPolicy();

        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage('Cluster connection policy was registered after the transport already took it');

        $context->registerConnectionPolicy($this->connectionPolicyAnswering(false));
    }

    public function testReadingOnePolicyDoorLeavesTheOtherOpen(): void
    {
        $connectionPolicy = $this->connectionPolicyAnswering(false);
        $placementPolicy = $this->placementPolicyPicking('node-b');

        $context = new ClusterContext();
        $context->placementPolicy();
        $context->registerConnectionPolicy($connectionPolicy);

        $this->assertSame($connectionPolicy, $context->connectionPolicy());

        $reopened = new ClusterContext();
        $reopened->connectionPolicy();
        $reopened->registerPlacementPolicy($placementPolicy);

        $this->assertSame($placementPolicy, $reopened->placementPolicy());
    }

    /**
     * Builds a placement policy that always names one node, to be recognised by identity.
     *
     * @param string $nodeId Node id the policy answers with
     * @return PlacementPolicy Policy naming that node
     */
    private function placementPolicyPicking(string $nodeId): PlacementPolicy
    {
        return new class ($nodeId) implements PlacementPolicy {
            /** @var string Node id this policy always names */
            private string $nodeId;

            /**
             * @param string $nodeId Node id this policy always names
             */
            public function __construct(string $nodeId)
            {
                $this->nodeId = $nodeId;
            }

            /**
             * @param list<string> $requiredTags Boolean capability tags the agent must have
             * @param ResourceProfile $cost Resource cost of the agent
             * @param array<string, PlacementCandidate> $candidates Online candidate nodes keyed by node id
             * @return ?string The one node id this policy names
             */
            public function selectNode(array $requiredTags, ResourceProfile $cost, array $candidates): ?string
            {
                return $this->nodeId;
            }
        };
    }

    /**
     * Builds a connection policy that always gives the same answer, to be recognised by identity.
     *
     * @param bool $answer What the policy says about every candidate
     * @return ConnectionPolicy Policy answering that way
     */
    private function connectionPolicyAnswering(bool $answer): ConnectionPolicy
    {
        return new class ($answer) implements ConnectionPolicy {
            /** @var bool What this policy says about every candidate */
            private bool $answer;

            /**
             * @param bool $answer What this policy says about every candidate
             */
            public function __construct(bool $answer)
            {
                $this->answer = $answer;
            }

            /**
             * @param NodeIdentity $local Local node identity
             * @param ClusterNode $candidate Known peer weighed as a dial target
             * @return bool The fixed answer this policy was built with
             */
            public function shouldDial(NodeIdentity $local, ClusterNode $candidate): bool
            {
                return $this->answer;
            }
        };
    }
}

/** Runtime context for the inspect case; it mounts only framework-owned rows. */
final class ClusterContextTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

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
use Hilos\Cluster\Leadership;
use Hilos\Cluster\LocalNodeAnnouncer;
use Hilos\Cluster\MembershipObserver;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeLifecycleState;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\ConnectionPolicy;
use Hilos\Cluster\Peer\FullMeshConnectionPolicy;
use Hilos\Cluster\Placement\BestFitPlacementPolicy;
use Hilos\Cluster\Placement\NodeCapacities;
use Hilos\Cluster\Placement\PlacementPolicy;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the cluster facade context: mode gating and identity access (HIL-177).
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

        $nodes = $inspection[ClusterCommandConstants::FIELD_NODES];
        $this->assertCount(1, $nodes);
        $this->assertSame('node-a', $nodes[0][ClusterCommandConstants::FIELD_NODE_ID]);
        $this->assertSame(['gpu-local'], $nodes[0][ClusterCommandConstants::FIELD_NODE_CAPABILITIES]);
        $this->assertTrue($nodes[0][ClusterCommandConstants::FIELD_NODE_ONLINE]);
        $this->assertArrayHasKey(ClusterCommandConstants::FIELD_NODE_LAST_SEEN, $nodes[0]);
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
             * @param ResourceProfile $profile Numeric hard minimums and soft preferences of the agent
             * @param array<string, NodeCapacities> $candidates Candidate nodes' capacities keyed by node id
             * @param array<string, int> $hosted Agents each candidate already hosts, keyed by node id
             * @return ?string The one node id this policy names
             */
            public function selectNode(
                array $requiredTags,
                ResourceProfile $profile,
                array $candidates,
                array $hosted = [],
            ): ?string {
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

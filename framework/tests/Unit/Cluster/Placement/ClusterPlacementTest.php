<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Exception\PlacementCapabilityException;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\PlacementObserver;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent-placement coordinator (HIL-179).
 *
 * The coordinator is driven against a fake mesh and executor, so the leader placement
 * side and the node execution side are exercised without a peer socket or a worker pool.
 * The self node is 'leader'; 'gpu-node' is a data-plane node advertising a capability.
 */
final class ClusterPlacementTest extends TestCase
{
    private const string SELF = 'leader';

    public function testLocalPlacementRunsTheLocalStartPathAndTracksStarted(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => []]);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        $placement->placeAgentOnNode('chat', '1', self::SELF);

        $this->assertSame([['chat', '1']], $executor->executed, 'A local placement runs the local start path');
        $this->assertSame([], $mesh->sent, 'A local placement sends no frame');
        $record = $placement->registry()->get('chat:1');
        $this->assertNotNull($record);
        $this->assertSame(self::SELF, $record->nodeId);
        $this->assertSame(PlacementState::Started, $record->state);
    }

    public function testLocalPlacementFailureTracksFailedAndRethrows(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => []]);
        $executor = new FakePlacementExecutor();
        $executor->failWith = new NoSuitableWorkerException('regular', false);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        try {
            $placement->placeAgentOnNode('chat', null, self::SELF);
            $this->fail('A failing local placement must surface the executor error');
        } catch (NoSuitableWorkerException) {
            // expected
        }

        $this->assertSame(PlacementState::Failed, $placement->registry()->get('chat')?->state);
    }

    public function testRemotePlacementSendsPlaceFrameAndTracksPending(): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => ['gpu']], linked: ['gpu-node']);
        $executor = new FakePlacementExecutor(['gpu']);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        $placement->placeAgentOnNode('render', '9', 'gpu-node');

        $this->assertSame([], $executor->executed, 'A remote placement does not run locally');
        $this->assertCount(1, $mesh->sent);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('gpu-node', $nodeId);
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame);
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:9')?->state);
    }

    public function testPlacementRejectsWhenTheNodeLacksARequiredCapability(): void
    {
        $mesh = new FakePlacementMesh(['plain-node' => []], linked: ['plain-node']);
        $executor = new FakePlacementExecutor(['gpu']);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        try {
            $placement->placeAgentOnNode('render', null, 'plain-node');
            $this->fail('A placement onto a node missing a required capability must be rejected');
        } catch (PlacementCapabilityException) {
            // expected
        }

        $this->assertSame([], $mesh->sent, 'Nothing is sent when the capability check fails');
        $this->assertSame(0, $placement->registry()->count());
    }

    public function testNodeExecutesPlaceFrameAndRepliesStarted(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));

        $this->assertSame([['render', '9']], $executor->executed);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('leader', $nodeId, 'The status reply goes back to the requesting leader');
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Started, $frame->state);
        $this->assertSame(5, $frame->workerId);
    }

    public function testNodeRepliesFailedWhenExecutionThrows(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->failWith = new NoSuitableWorkerException('regular', false);
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null));

        $frame = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Failed, $frame->state);
        $this->assertNotNull($frame->error);
    }

    public function testLeaderTracksAStartedStatusAgainstTheReportingNode(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());

        $placement->onAgentStatus('gpu-node', PeerAgentStatusDTO::started('render', '9', 5));

        $record = $placement->registry()->get('render:9');
        $this->assertSame('gpu-node', $record?->nodeId);
        $this->assertSame(PlacementState::Started, $record?->state);
    }

    public function testLeaderForgetsAPlacementOnAStoppedStatus(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());
        $placement->onAgentStatus('gpu-node', PeerAgentStatusDTO::started('render', '9', 5));

        $placement->onAgentStatus('gpu-node', PeerAgentStatusDTO::stopped('render', '9'));

        $this->assertNull($placement->registry()->get('render:9'));
    }

    public function testStopOnARemoteNodeSendsAStopFrameAndForgets(): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => ['gpu']], linked: ['gpu-node']);
        $executor = new FakePlacementExecutor(['gpu']);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->placeAgentOnNode('render', '9', 'gpu-node');
        $mesh->sent = [];

        $placement->stopAgentOnNode('render', '9', 'gpu-node');

        $this->assertInstanceOf(PeerStopAgentDTO::class, $mesh->sent[0][1]);
        $this->assertNull($placement->registry()->get('render:9'));
    }

    public function testNodeAnswersAQueryWithItsHostedSet(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $placement = new ClusterPlacement('gpu-node', $mesh, new FakePlacementExecutor(workerId: 5));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));
        $mesh->sent = [];

        $placement->onPlacementQuery('leader');

        $report = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $report);
        $this->assertCount(1, $report->agents);
        $this->assertSame('render', $report->agents[0]->agentType);
    }

    public function testBecomingLeaderBroadcastsAQueryAndRebuildsFromReports(): void
    {
        $mesh = new FakePlacementMesh([]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        // A stale entry from a previous term must be cleared on the fresh rebuild.
        $placement->onAgentStatus('old-node', PeerAgentStatusDTO::started('stale', null, 1));

        $placement->onBecameLeader();

        $this->assertSame(0, $placement->registry()->count(), 'The view is cleared before the rebuild');
        $this->assertInstanceOf(PeerPlacementQueryDTO::class, $mesh->broadcast[0]);

        $placement->onPlacementReport('gpu-node', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        $record = $placement->registry()->get('render:9');
        $this->assertSame('gpu-node', $record?->nodeId);
        $this->assertSame(PlacementState::Started, $record?->state);
    }

    public function testNodeForReportsTheHostingNodeOfARemotelyPlacedAgent(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());
        $placement->onAgentStatus('gpu-node', PeerAgentStatusDTO::started('render', '9', 5));

        $this->assertSame('gpu-node', $placement->nodeFor('render', '9'));
    }

    public function testNodeForReturnsNullForALocallyPlacedAgent(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => []]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->placeAgentOnNode('chat', '1', self::SELF);

        // The self short-circuit lives in the lookup, so a local agent routes locally.
        $this->assertNull($placement->nodeFor('chat', '1'));
    }

    public function testNodeForReturnsNullForAnUnknownAgent(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());

        $this->assertNull($placement->nodeFor('never_placed', null));
    }

    public function testLeaderReplacesADeadNodesAgentOnlyAfterTheFailoverGrace(): void
    {
        // 'leader' lacks the gpu tag, so the only capable target is the spare 'node-c'.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu'], 'node-c' => ['gpu']],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), null, failoverGraceMs: 500, slaveWorkGraceMs: 250);
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        // node-b dies; the spare is the only survivor able to host the agent.
        $mesh->online = [self::SELF, 'node-c'];
        $placement->noteNodeOffline('node-b', 1000.0);

        $placement->tick(1000.4);
        $this->assertSame([], $mesh->sent, 'Nothing is re-placed before the failover grace elapses');
        $this->assertSame('node-b', $placement->registry()->get('render:9')?->nodeId);

        $placement->tick(1000.6);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('node-c', $nodeId, 'The agent is re-placed onto the surviving capable node');
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame);
        $this->assertSame('node-c', $placement->registry()->get('render:9')?->nodeId);
    }

    public function testAFlappedNodeBackBeforeGraceCancelsItsFailover(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu'], 'node-c' => ['gpu']],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), null, failoverGraceMs: 500);
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        $placement->noteNodeOffline('node-b', 1000.0);
        $placement->noteNodeOnline('node-b', 1000.2);
        $placement->tick(1000.9);

        $this->assertSame([], $mesh->sent, 'A node back before its grace keeps its agents; no re-placement');
        $this->assertSame('node-b', $placement->registry()->get('render:9')?->nodeId);
    }

    public function testFailoverDegradesToUnplacedThenRetriesWhenACapableNodeJoins(): void
    {
        // No capable node besides the dead one: failover has nowhere to go.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu']],
            linked: ['node-b'],
            online: [self::SELF, 'node-b'],
        );
        $observer = new FakePlacementObserver();
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), $observer, failoverGraceMs: 500);
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        $mesh->online = [self::SELF];
        $placement->noteNodeOffline('node-b', 1000.0);
        $placement->tick(1000.6);

        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:9')?->state);
        $this->assertSame([['render', '9']], $observer->degraded, 'The degradation is reported to the observer');
        $this->assertNull($placement->nodeFor('render', '9'), 'An unplaced agent routes nowhere');

        // A capable node joins: the leader retries the unplaced agent onto it.
        $mesh->capabilities['node-c'] = ['gpu'];
        $mesh->linked[] = 'node-c';
        $mesh->online = [self::SELF, 'node-c'];
        $placement->noteNodeOnline('node-c', 2000.0);

        $record = $placement->registry()->get('render:9');
        $this->assertSame('node-c', $record?->nodeId, 'The previously-unplaced agent is placed on the newcomer');
        $this->assertNotSame(PlacementState::Unplaced, $record?->state);
    }

    public function testSlaveSelfFencesItsHostedAgentsWhenIsolatedFromThePlacingLeader(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->tick(1000.4);
        $this->assertSame([], $executor->revoked, 'The slave keeps working through the grace window');

        $placement->tick(1000.6);
        $this->assertSame([['render', '9']], $executor->revoked, 'The slave stops its placed agents once isolated past the grace');
    }

    public function testSlaveCancelsSelfFenceWhenThePlacingLeaderReturnsInTime(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->noteNodeOnline('leader', 1000.2);
        $placement->tick(1000.9);

        $this->assertSame([], $executor->revoked, 'A leader back before the grace leaves the slave running');
    }

    public function testLeaderReconcilesARejoinReportByStoppingAMovedAgent(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['node-b', 'node-c']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        // The leader already re-placed render:9 onto node-c while node-b was gone.
        $placement->onPlacementReport('node-c', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        // node-b rejoins reporting it still hosts the moved agent.
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('node-b', $nodeId);
        $this->assertInstanceOf(PeerStopAgentDTO::class, $frame, 'The returning node is told to stop the moved agent');
        $this->assertSame('node-c', $placement->registry()->get('render:9')?->nodeId, 'The leader-owned placement is unchanged');
    }

    public function testRejoinReportSendsHostedAgentsAndANonLeaderIgnoresReports(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $placement = new ClusterPlacement('slave', $mesh, new FakePlacementExecutor(workerId: 5));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));
        $mesh->sent = [];

        // On rejoin the node reports what it still hosts...
        $placement->onPeerHandshaked('leader');
        [$nodeId, $report] = $mesh->sent[0];
        $this->assertSame('leader', $nodeId);
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $report);
        $this->assertSame('render', $report->agents[0]->agentType);

        // ...but a non-leader that receives a report folds nothing into its inert view.
        $placement->onPlacementReport('other', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('ghost', null)]));
        $this->assertNull($placement->registry()->get('ghost'));
    }

    public function testPlaceAgentOnBestNodePicksTheStrongestCapableNodeAndPlaces(): void
    {
        // Both data-plane nodes are capable; the stronger one (more cpu) should win the pick.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['worker', 'cpu=2'], 'node-c' => ['worker', 'cpu=8']],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));

        $target = $placement->placeAgentOnBestNode('render', null);

        $this->assertSame('node-c', $target, 'Best-fit places on the strongest capable node');
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('node-c', $nodeId);
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame);
        $this->assertSame('node-c', $placement->registry()->get('render')?->nodeId);
    }

    public function testPlaceAgentOnBestNodeReturnsNullWhenNoNodeIsAFit(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['worker']],
            linked: ['node-b'],
            online: [self::SELF, 'node-b'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));

        $this->assertNull($placement->placeAgentOnBestNode('render', null), 'No gpu node is online, so nothing is placed');
        $this->assertSame([], $mesh->sent, 'Nothing is sent when no node clears the hard gate');
        $this->assertSame(0, $placement->registry()->count());
    }

    public function testBestFitPrefersACapacityThePreferenceWeights(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['few-gpu' => ['worker', 'gpu=1'], 'many-gpu' => ['worker', 'gpu=4']],
            linked: ['few-gpu', 'many-gpu'],
            online: [self::SELF, 'few-gpu', 'many-gpu'],
        );
        $profile = ResourceProfile::create(preferences: ['gpu' => 1.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker'], profile: $profile));

        $this->assertSame('many-gpu', $placement->placeAgentOnBestNode('render', null));
    }

    public function testNamedPlacementRejectsWhenACapacityMinimumIsUnmet(): void
    {
        $mesh = new FakePlacementMesh(['weak' => ['worker', 'ram=8']], linked: ['weak'], online: [self::SELF, 'weak']);
        $profile = ResourceProfile::create(minimums: ['ram' => 32.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker'], profile: $profile));

        try {
            $placement->placeAgentOnNode('render', null, 'weak');
            $this->fail('A placement onto a node below a required capacity minimum must be rejected');
        } catch (PlacementCapabilityException) {
            // expected
        }

        $this->assertSame([], $mesh->sent, 'Nothing is sent when the resource minimum is unmet');
        $this->assertSame(0, $placement->registry()->count());
    }

    public function testFailoverReplacesOntoTheStrongestSurvivingCapableNode(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['worker', 'cpu=1'], 'node-c' => ['worker', 'cpu=2'], 'node-d' => ['worker', 'cpu=9']],
            linked: ['node-b', 'node-c', 'node-d'],
            online: [self::SELF, 'node-b', 'node-c', 'node-d'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']), null, failoverGraceMs: 500);
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        // node-b dies; two survivors are capable, so best-fit takes the stronger of them.
        $mesh->online = [self::SELF, 'node-c', 'node-d'];
        $placement->noteNodeOffline('node-b', 1000.0);
        $placement->tick(1000.6);

        $this->assertSame('node-d', $mesh->sent[0][0], 'Failover re-places onto the strongest surviving capable node');
        $this->assertSame('node-d', $placement->registry()->get('render:9')?->nodeId);
    }
}

/**
 * Fake mesh that records sent and broadcast frames and answers capability and online-set
 * lookups from mutable maps, so the coordinator runs without a peer socket. The maps are
 * public so a failover test can flip a node online/offline or add a capability mid-run.
 */
final class FakePlacementMesh implements PlacementMesh
{
    /** @var list<array{0: string, 1: PeerDTO}> Node-addressed frames, as [nodeId, frame] */
    public array $sent = [];

    /** @var list<PeerDTO> Broadcast frames */
    public array $broadcast = [];

    /** @var array<string, list<string>> Advertised capabilities keyed by node id */
    public array $capabilities;

    /** @var list<string> Node ids a link exists to (sendToNode succeeds) */
    public array $linked;

    /** @var list<string> Currently-online node ids */
    public array $online;

    /**
     * @param array<string, list<string>> $capabilities Advertised capabilities keyed by node id
     * @param list<string> $linked Node ids a link exists to (sendToNode succeeds)
     * @param list<string> $online Currently-online node ids
     */
    public function __construct(array $capabilities, array $linked = [], array $online = [])
    {
        $this->capabilities = $capabilities;
        $this->linked = $linked;
        $this->online = $online;
    }

    public function sendToNode(string $nodeId, PeerDTO $frame): bool
    {
        $this->sent[] = [$nodeId, $frame];

        return in_array($nodeId, $this->linked, true);
    }

    public function broadcastToNodes(PeerDTO $frame): void
    {
        $this->broadcast[] = $frame;
    }

    public function nodeCapabilities(string $nodeId): ?array
    {
        return $this->capabilities[$nodeId] ?? null;
    }

    public function onlineNodeIds(): array
    {
        return $this->online;
    }
}

/**
 * Fake placement observer that records the agents failover degraded to unplaced.
 */
final class FakePlacementObserver implements PlacementObserver
{
    /** @var list<array{0: string, 1: ?string}> Degraded agents, as [type, index] */
    public array $degraded = [];

    public function onPlacementDegraded(string $agentType, ?string $agentIndex): void
    {
        $this->degraded[] = [$agentType, $agentIndex];
    }
}

/**
 * Fake executor that records placements and revokes, and can be told to fail or to report
 * a fixed required-capability set and worker id.
 */
final class FakePlacementExecutor implements PlacementExecutor
{
    /** @var list<array{0: string, 1: ?string}> Executed placements, as [type, index] */
    public array $executed = [];

    /** @var list<array{0: string, 1: ?string}> Revoked placements, as [type, index] */
    public array $revoked = [];

    /** @var ?\Throwable Exception the next executePlacement() should throw, or null to succeed */
    public ?\Throwable $failWith = null;

    /** @var ResourceProfile Resource profile every agent type reports */
    private readonly ResourceProfile $profile;

    /**
     * @param list<string> $required Required capabilities every agent type reports
     * @param int $workerId Worker id a successful placement lands on
     * @param ?ResourceProfile $profile Resource profile every agent type reports; empty when null
     */
    public function __construct(
        private readonly array $required = [],
        private readonly int $workerId = 1,
        ?ResourceProfile $profile = null,
    ) {
        $this->profile = $profile ?? ResourceProfile::none();
    }

    public function requiredCapabilities(string $agentType, ?string $agentIndex): array
    {
        return $this->required;
    }

    public function placementProfile(string $agentType, ?string $agentIndex): ResourceProfile
    {
        return $this->profile;
    }

    public function executePlacement(string $agentType, ?string $agentIndex): int
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->executed[] = [$agentType, $agentIndex];

        return $this->workerId;
    }

    public function revokePlacement(string $agentType, ?string $agentIndex): void
    {
        $this->revoked[] = [$agentType, $agentIndex];
    }
}

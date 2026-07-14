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
use Hilos\Cluster\Placement\PlacementState;
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
}

/**
 * Fake mesh that records sent and broadcast frames and answers capability lookups from a
 * fixed map, so the coordinator runs without a peer socket.
 */
final class FakePlacementMesh implements PlacementMesh
{
    /** @var list<array{0: string, 1: PeerDTO}> Node-addressed frames, as [nodeId, frame] */
    public array $sent = [];

    /** @var list<PeerDTO> Broadcast frames */
    public array $broadcast = [];

    /**
     * @param array<string, list<string>> $capabilities Advertised capabilities keyed by node id
     * @param list<string> $linked Node ids a link exists to (sendToNode succeeds)
     */
    public function __construct(
        private readonly array $capabilities,
        private readonly array $linked = [],
    ) {
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

    /**
     * @param list<string> $required Required capabilities every agent type reports
     * @param int $workerId Worker id a successful placement lands on
     */
    public function __construct(
        private readonly array $required = [],
        private readonly int $workerId = 1,
    ) {
    }

    public function requiredCapabilities(string $agentType, ?string $agentIndex): array
    {
        return $this->required;
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

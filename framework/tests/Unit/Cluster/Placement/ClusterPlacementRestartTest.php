<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Placement\PlacementId;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Closure;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRestartSink;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ResourceProfile;
use PHPUnit\Framework\TestCase;

/** POLICY restart placement waits for the old host's stop before launching elsewhere. */
final class ClusterPlacementRestartTest extends TestCase
{
    private const string HOST = 'z-host';
    private const string TARGET = 'a-target';
    private const string SLOTS = 'slots=10';

    public function testRestartKeepsOldRecordUntilStoppedThenReportsTheNewHost(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        self::assertSame(self::TARGET, $placement->bestNodeFor('chat', '7'));
        self::assertSame(self::HOST, $placement->registry()->get('chat:7')?->nodeId);

        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        self::assertSame(self::HOST, $placement->registry()->get('chat:7')?->nodeId);
        self::assertInstanceOf(PeerStopAgentDTO::class, $mesh->sent[0][1]);
        self::assertSame([], $sink->placed);

        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        self::assertSame(self::TARGET, $placement->registry()->get('chat:7')?->nodeId);
        self::assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[1][1]);
        self::assertSame([], $sink->placed);

        $order = $mesh->sent[1][1]->placementId;
        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::started('chat', '7', 3, $order, $order->term));
        self::assertSame(PlacementState::Started, $placement->registry()->get('chat:7')?->state);
        self::assertSame([], $sink->placed);
        self::assertSame([], $sink->notPlaced);

        $oldOrder = new PlacementId($order->leaderNodeId, $order->term - 1, $order->sequence);
        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::started('chat', '7', 3, $oldOrder, $order->term, workerStarted: true));
        $ready = PeerAgentStatusDTO::started('chat', '7', 3, $order, $order->term, workerStarted: true);
        $placement->onAgentStatus(self::HOST, $ready);
        self::assertSame([], $sink->placed);

        $placement->onAgentStatus(self::TARGET, $ready);
        $placement->onAgentStatus(self::TARGET, $ready);
        self::assertSame([['chat', '7', self::TARGET]], $sink->placed);
        self::assertSame([], $sink->notPlaced);
    }

    public function testTargetThatLosesItsCapabilityFallsBackToTheBestRemainingNode(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        unset($mesh->capabilities[self::TARGET]);

        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));

        self::assertSame(self::HOST, $placement->registry()->get('chat:7')?->nodeId);
        self::assertSame(self::HOST, $mesh->sent[1][0]);
        self::assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[1][1]);
        $order = $mesh->sent[1][1]->placementId;
        $placement->onAgentStatus(self::HOST, PeerAgentStatusDTO::started('chat', '7', 4, $order, $order->term, workerStarted: true));
        self::assertSame([['chat', '7', self::HOST]], $sink->placed);
    }

    public function testNoRemainingNodeReportsARefusalAfterStop(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        $mesh->capabilities = [];

        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));

        self::assertSame(PlacementState::Unplaced, $placement->registry()->get('chat:7')?->state);
        self::assertNull($placement->registry()->get('chat:7')?->nodeId);
        self::assertSame([['chat', '7', 'no capable node is available']], $sink->notPlaced);
    }

    public function testNewHostFailureReportsTheReason(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        self::assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[1][1]);
        $order = $mesh->sent[1][1]->placementId;
        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::started('chat', '7', 3, $order, $order->term));
        $failed = PeerAgentStatusDTO::failed('chat', '7', 'start refused', placementId: $order, authorityTerm: $order->term);
        $placement->onAgentStatus(self::TARGET, $failed);
        $placement->onAgentStatus(self::TARGET, $failed);

        self::assertSame([], $sink->placed);
        self::assertSame([['chat', '7', 'start refused']], $sink->notPlaced);
    }

    public function testLocalTargetWaitsForTheWorkerStartFact(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $mesh->capabilities['leader'] = [self::SLOTS];
        $placement->restartElsewhere('chat', '7', self::HOST, 'leader');

        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        self::assertSame([], $sink->placed);
        self::assertSame(PlacementState::Started, $placement->registry()->get('chat:7')?->state);

        $placement->noteAgentStarted('chat', '7', placementId: $placement->localPlacementId('chat:7'));
        self::assertSame([['chat', '7', 'leader']], $sink->placed);
    }

    public function testReplacementWorkerLossWaitsForTheLocalWrites(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $mesh->capabilities['leader'] = [self::SLOTS];
        $placement->restartElsewhere('chat', '7', self::HOST, 'leader');
        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        $order = $placement->localPlacementId('chat:7');
        self::assertNotNull($order);

        $afterWrites = null;
        $placement->noteAgentStopped('chat', '7', static function (Closure $settled) use (&$afterWrites): void {
            $afterWrites = $settled;
        });
        self::assertSame('leader', $placement->registry()->get('chat:7')?->nodeId);
        self::assertSame([], $sink->notPlaced);
        self::assertNotNull($afterWrites);
        $afterWrites();

        self::assertFalse($placement->registry()->get('chat:7')?->state->hostsAgent() ?? false);
        self::assertSame([['chat', '7', 'its worker was lost']], $sink->notPlaced);
        $placement->noteAgentStarted('chat', '7', placementId: $order);
        $afterWrites();
        self::assertSame([], $sink->placed);
        self::assertCount(1, $sink->notPlaced);
    }

    public function testReplacementStopIsDistinctFromTheOldStopAcknowledgement(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $oldOrder = new PlacementId('leader', 1, 1);
        $placement->registry()->put(new PlacementRecord('chat', '7', self::HOST, PlacementState::Started, placementId: $oldOrder));
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        $stop = $mesh->sent[0][1];
        self::assertInstanceOf(PeerStopAgentDTO::class, $stop);
        $oldStopped = PeerAgentStatusDTO::stopped('chat', '7', $oldOrder, $stop->authorityTerm, $stop->stopId);
        $placement->onAgentStatus(self::HOST, $oldStopped);
        $place = $mesh->sent[1][1];
        self::assertInstanceOf(PeerPlaceAgentDTO::class, $place);
        $order = $place->placementId;
        $targetLost = PeerAgentStatusDTO::stopped('chat', '7', $order, $order->term);
        $placement->onAgentStatus(self::HOST, $targetLost);
        $placement->onAgentStatus(self::HOST, $oldStopped);
        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::stopped('chat', '7', $order, $order->term, $stop->stopId));
        self::assertSame([], $sink->notPlaced);

        $placement->onAgentStatus(self::TARGET, $targetLost);
        self::assertSame([['chat', '7', 'its worker was lost']], $sink->notPlaced);
        self::assertFalse($placement->registry()->get('chat:7')?->state->hostsAgent() ?? false);

        $placement->placeAgentOnNode('chat', '7', self::TARGET);
        $new = $placement->registry()->get('chat:7')?->placementId;
        self::assertNotNull($new);
        self::assertFalse($order->equals($new));
        $placement->onAgentStatus(self::TARGET, $targetLost);
        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::started('chat', '7', 3, $order, $order->term, workerStarted: true));
        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::failed('chat', '7', 'late', placementId: $order, authorityTerm: $order->term));
        self::assertSame($new, $placement->registry()->get('chat:7')?->placementId);
        self::assertSame([], $sink->placed);
        self::assertCount(1, $sink->notPlaced);
    }

    public function testAckReportWithPendingOrderKeepsTheRestartOpenUntilWorkerProof(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        $order = $placement->registry()->get('chat:7')?->placementId;
        self::assertNotNull($order);
        $placement->tick(1001.0);
        $placement->tick(1100.0);
        $query = $mesh->takeFixtureQuery(self::TARGET);
        self::assertNotNull($query);
        $proof = new PeerPlacedAgentEntry('chat', '7', $order, PlacementState::Started, workerStarted: true);
        $placement->onPlacementReport(self::HOST, new PeerPlacementReportDTO([$proof], queryId: $query->queryId));
        $placement->onPlacementReport(self::TARGET, new PeerPlacementReportDTO([$proof], queryId: 'old-query'));
        $placement->onPlacementReport(self::TARGET, new PeerPlacementReportDTO([]));
        $pending = new PeerPlacedAgentEntry('chat', '7', $order, PlacementState::Placing);
        $placement->onPlacementReport(self::TARGET, new PeerPlacementReportDTO([$pending], queryId: $query->queryId));
        self::assertSame([], $sink->placed);
        self::assertSame([], $sink->notPlaced);
        self::assertSame(PlacementState::Placing, $placement->registry()->get('chat:7')?->state);

        $placement->onPeerHandshaked(self::TARGET);
        $query = $mesh->takeFixtureQuery(self::TARGET);
        self::assertNotNull($query);
        $report = new PeerPlacementReportDTO([$proof], queryId: $query->queryId);
        $placement->onPlacementReport(self::TARGET, $report);
        $placement->onPlacementReport(self::TARGET, $report);
        self::assertSame([['chat', '7', self::TARGET]], $sink->placed);
        self::assertSame([], $sink->notPlaced);
    }

    public function testExactAckWorkerProofFinishesTheRestart(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        $order = $placement->registry()->get('chat:7')?->placementId;
        self::assertNotNull($order);
        $placement->tick(1001.0);
        $placement->tick(1100.0);
        $query = $mesh->takeFixtureQuery(self::TARGET);
        self::assertNotNull($query);
        $report = new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('chat', '7', $order, PlacementState::Started, workerStarted: true),
        ], queryId: $query->queryId);

        $placement->onPlacementReport(self::TARGET, $report);
        $placement->onPlacementReport(self::TARGET, $report);
        self::assertSame([['chat', '7', self::TARGET]], $sink->placed);
        self::assertSame([], $sink->notPlaced);
    }

    public function testOnlyARejoinQueryAfterTheReplacementOrderCanAttestItsAbsence(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->onPeerHandshaked(self::TARGET);
        $old = $mesh->takeFixtureQuery(self::TARGET);
        self::assertNotNull($old);
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        $order = $placement->registry()->get('chat:7')?->placementId;
        self::assertNotNull($order);
        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::started('chat', '7', 3, $order, $order->term));

        $placement->onPlacementReport(self::TARGET, new PeerPlacementReportDTO([], queryId: $old->queryId));
        self::assertSame($order, $placement->registry()->get('chat:7')?->placementId);
        self::assertSame([], $sink->notPlaced);

        $placement->onPeerHandshaked(self::TARGET);
        $query = $mesh->takeFixtureQuery(self::TARGET);
        self::assertNotNull($query);
        $report = new PeerPlacementReportDTO([], queryId: $query->queryId);
        $placement->onPlacementReport(self::TARGET, $report);
        $placement->onPlacementReport(self::TARGET, $report);
        self::assertSame([['chat', '7', 'its worker was lost']], $sink->notPlaced);
        self::assertSame([], $sink->placed);
        self::assertNotSame($order, $placement->registry()->get('chat:7')?->placementId);
    }

    public function testExactAckAbsenceFinishesTheRestartBeforeOrdinaryReplacement(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        $order = $placement->registry()->get('chat:7')?->placementId;
        self::assertNotNull($order);
        $placement->tick(1001.0);
        $placement->tick(1100.0);
        $query = $mesh->takeFixtureQuery(self::TARGET);
        self::assertNotNull($query);
        self::assertSame([], $sink->notPlaced);

        $placement->onPlacementReport(self::TARGET, new PeerPlacementReportDTO([], queryId: $query->queryId));
        self::assertSame([['chat', '7', 'its worker was lost']], $sink->notPlaced);
        $record = $placement->registry()->get('chat:7');
        self::assertNotNull($record?->placementId);
        self::assertFalse($order->equals($record->placementId));
        $placement->onAgentStatus($record->nodeId, PeerAgentStatusDTO::started(
            'chat', '7', 3, $record->placementId, $record->placementId->term, workerStarted: true,
        ));
        self::assertSame([], $sink->placed);
        self::assertCount(1, $sink->notPlaced);
    }

    public function testExactAckWithAnOlderOrderRefusesTheMoveAndFencesTheWriter(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        $order = $placement->registry()->get('chat:7')->placementId;
        $older = new PlacementId($order->leaderNodeId, $order->term, $order->sequence - 1);
        $placement->tick(1001.0);
        $placement->tick(1100.0);
        $query = $mesh->takeFixtureQuery(self::TARGET);
        self::assertNotNull($query);
        $entries = [new PeerPlacedAgentEntry('chat', '7', $older, PlacementState::Started, workerStarted: true)];
        $placement->onPlacementReport(self::TARGET, new PeerPlacementReportDTO($entries));
        $placement->onPlacementReport(self::HOST, new PeerPlacementReportDTO($entries, queryId: $query->queryId));
        self::assertSame([], $sink->notPlaced);

        $mesh->sent = [];
        $report = new PeerPlacementReportDTO($entries, queryId: $query->queryId);
        $placement->onPlacementReport(self::TARGET, $report);
        self::assertSame([['chat', '7', 'its worker was lost']], $sink->notPlaced);
        self::assertSame(PlacementState::Stopped, $placement->registry()->get('chat:7')->state);
        self::assertSame($older, $placement->registry()->get('chat:7')->placementId);
        self::assertCount(1, $mesh->sent);
        [$node, $stop] = $mesh->sent[0];
        self::assertSame(self::TARGET, $node);
        self::assertInstanceOf(PeerStopAgentDTO::class, $stop);
        self::assertSame($older, $stop->placementId);
        $placement->onPlacementReport(self::TARGET, $report);
        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::started('chat', '7', 3, $order, $order->term, workerStarted: true));
        self::assertNull($placement->placeAgentOnBestNode('chat', '7'));
        self::assertCount(1, $mesh->sent);

        $placement->onAgentStatus(self::TARGET, $stop->stoppedStatus());
        $new = $placement->registry()->get('chat:7')->placementId;
        self::assertGreaterThan($order->sequence, $new->sequence);
        self::assertSame([], $sink->placed);
        self::assertCount(1, $sink->notPlaced);
    }

    public function testReentrantRejoinProofFinishesBeforeTheOrderEntersTheRegistry(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $mesh->onDelivered = static function (string $node, PeerDTO $frame) use ($placement, $mesh, $sink): void {
            if (!$frame instanceof PeerPlaceAgentDTO) {
                return;
            }
            self::assertNull($placement->registry()->get('chat:7'));
            $placement->onPeerHandshaked($node);
            $query = $mesh->takeFixtureQuery($node);
            self::assertNotNull($query);
            $proof = new PeerPlacedAgentEntry('chat', '7', $frame->placementId, PlacementState::Started, workerStarted: true);
            $report = new PeerPlacementReportDTO([$proof], queryId: $query->queryId);
            $placement->onPlacementReport($node, $report);
            self::assertSame([['chat', '7', $node]], $sink->placed);
            $placement->onPlacementReport($node, $report);
        };
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        self::assertSame([['chat', '7', self::TARGET]], $sink->placed);
        self::assertSame([], $sink->notPlaced);
    }

    public function testSynchronousFallbackWorkerProofConsumesTheMove(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        unset($mesh->capabilities[self::TARGET]);
        $mesh->onDelivered = static function (string $nodeId, PeerDTO $frame) use ($placement): void {
            if ($frame instanceof PeerPlaceAgentDTO) {
                $placement->onAgentStatus($nodeId, PeerAgentStatusDTO::started(
                    'chat', '7', 3, $frame->placementId, $frame->placementId->term, workerStarted: true,
                ));
            }
        };

        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        self::assertSame([['chat', '7', self::HOST]], $sink->placed);
        self::assertSame([], $sink->notPlaced);
        $placement->noteNodeOffline(self::HOST, 1000.0);
        self::assertSame([], $sink->notPlaced);
    }

    public function testSilentOldHostLeavesItsRecordAndReportsRefusal(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $mesh->silent = [self::HOST];

        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);

        self::assertSame(self::HOST, $placement->registry()->get('chat:7')?->nodeId);
        self::assertSame([['chat', '7', 'node z-host is silent']], $sink->notPlaced);
    }

    public function testLostLeadershipForgetsThePendingMove(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        $placement->onLostLeadership();
        $mesh->sent = [];

        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));

        self::assertSame([], $mesh->sent);
        self::assertSame([], $sink->placed);
    }

    public function testOldHostLeavingAfterStopDoesNotCancelTheNewPlacement(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));

        $placement->noteNodeOffline(self::HOST, 100.0);
        self::assertSame([], $sink->notPlaced);

        self::assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[1][1]);
        $order = $mesh->sent[1][1]->placementId;
        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::started('chat', '7', 3, $order, $order->term, workerStarted: true));
        self::assertSame([['chat', '7', self::TARGET]], $sink->placed);
    }

    public function testInPlaceRestartRetainsPlacementAcrossOldStopAndReportsFailure(): void
    {
        $mesh = new RestartTestMesh([self::HOST => [self::SLOTS]]);
        $placement = new ClusterPlacement(self::HOST, $mesh, new RestartTestExecutor());
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('chat', '7', new PlacementId('leader', 0, 1)));
        $mesh->sent = [];

        $placement->beginInPlaceRestart('chat', '7');
        $placement->noteAgentStopped('chat', '7');
        self::assertSame([], $mesh->sent);
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('query', 0));
        self::assertInstanceOf(PeerPlacementReportDTO::class, $mesh->sent[0][1]);
        self::assertSame('chat', $mesh->sent[0][1]->agents[0]->agentType);
        $mesh->sent = [];

        $placement->endInPlaceRestart('chat', '7', false, 'start refused');
        self::assertCount(1, $mesh->sent);
        self::assertSame('leader', $mesh->sent[0][0]);
        self::assertInstanceOf(PeerAgentStatusDTO::class, $mesh->sent[0][1]);
        self::assertSame(PlacementState::Failed, $mesh->sent[0][1]->state);
    }

    public function testTruthSourceRefusalWinsOverAStopDuringRestart(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        $placement->registry()->put(new PlacementRecord('chat', '7', self::HOST, PlacementState::Refused));

        FakePlacementMesh::status($placement, self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));

        self::assertSame(PlacementState::Refused, $placement->registry()->get('chat:7')?->state);
        self::assertCount(1, $mesh->sent);
        self::assertSame([['chat', '7', 'placement refused by the truth-source guard']], $sink->notPlaced);
    }

    /** @return array{ClusterPlacement, RestartTestMesh, RestartTestSink} Isolated placement harness */
    private function fixture(): array
    {
        $mesh = new RestartTestMesh([
            self::HOST => [self::SLOTS],
            self::TARGET => [self::SLOTS],
        ]);
        $sink = new RestartTestSink();
        $placement = new ClusterPlacement('leader', $mesh, new RestartTestExecutor(), restartSink: $sink);
        $placement->onBecameLeader(1000.0, term: 1);
        FakePlacementMesh::reply($placement, self::HOST, new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, self::TARGET, new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->registry()->put(new PlacementRecord('chat', '7', self::HOST, PlacementState::Started));

        return [$placement, $mesh, $sink];
    }
}

/** Peer links and capabilities mutable at the stop boundary. */
final class RestartTestMesh implements PlacementMesh
{
    use PlacementQueryFixture;

    /** @var list<array{string, PeerDTO}> */
    public array $sent = [];

    /** @var list<string> Nodes whose link has gone silent */
    public array $silent = [];

    public ?Closure $onDelivered = null;

    /** @param array<string, list<string>> $capabilities Node capabilities */
    public function __construct(public array $capabilities)
    {
    }

    public function sendToNode(string $nodeId, PeerDTO $frame): bool
    {
        $this->sent[] = [$nodeId, $frame];
        $this->captureFixtureQuery($frame, $nodeId);
        if (in_array($nodeId, $this->silent, true)) {
            return false;
        }

        $this->onDelivered?->__invoke($nodeId, $frame);
        return true;
    }

    public function broadcastToNodes(PeerDTO $frame): void
    {
        $this->captureFixtureQuery($frame);
    }

    public function nodeCapabilities(string $nodeId): ?array
    {
        return $this->capabilities[$nodeId] ?? null;
    }

    public function onlineNodeIds(): array
    {
        return array_keys($this->capabilities);
    }

    public function afterRtWritesAnswered(Closure $then): void
    {
        $then();
    }
}

/** Local executor used only for capability and cost reads. */
final class RestartTestExecutor implements PlacementExecutor
{
    public function requiredCapabilities(string $agentType, ?string $agentIndex): array
    {
        return [];
    }

    public function placementProfile(string $agentType, ?string $agentIndex): ResourceProfile
    {
        return ResourceProfile::none();
    }

    /**
     * @param string $agentType Fixture input
     * @param ?string $agentIndex Fixture input
     * @param ?AgentStartSink $immediateStartSink Synchronous notification port for this call
     * @return ?int Fixture placement result
     */
    public function executePlacement(string $agentType, ?string $agentIndex, ?AgentStartSink $immediateStartSink = null): ?int
    {
        return 1;
    }

    public function placedWorkerId(string $agentType, ?string $agentIndex): ?int
    {
        return 1;
    }

    /**
     * @param string $agentType Fixture input
     * @param ?string $agentIndex Fixture input
     * @param ?Closure $stopped Fixture input
     * @param ?AgentStartSink $immediateStartSink Synchronous notification port for this call
     */
    public function revokePlacement(
        string $agentType,
        ?string $agentIndex,
        ?Closure $stopped = null,
        ?AgentStartSink $immediateStartSink = null,
    ): void
    {
        $stopped?->__invoke();
    }
}

/** Captures the final restart result sent to the master. */
final class RestartTestSink implements PlacementRestartSink
{
    /** @var list<array{string, ?string, string}> */
    public array $placed = [];

    /** @var list<array{string, ?string, string}> */
    public array $notPlaced = [];

    public function onRestartPlaced(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        $this->placed[] = [$agentType, $agentIndex, $nodeId];
    }

    public function onRestartNotPlaced(string $agentType, ?string $agentIndex, string $reason): void
    {
        $this->notPlaced[] = [$agentType, $agentIndex, $reason];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

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

        $placement->onAgentStatus(self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        self::assertSame(self::TARGET, $placement->registry()->get('chat:7')?->nodeId);
        self::assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[1][1]);
        self::assertSame([], $sink->placed);

        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::started('chat', '7', 3));
        self::assertSame([['chat', '7', self::TARGET]], $sink->placed);
        self::assertSame([], $sink->notPlaced);
    }

    public function testTargetThatLosesItsCapabilityFallsBackToTheBestRemainingNode(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        unset($mesh->capabilities[self::TARGET]);

        $placement->onAgentStatus(self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));

        self::assertSame(self::HOST, $placement->registry()->get('chat:7')?->nodeId);
        self::assertSame(self::HOST, $mesh->sent[1][0]);
        $placement->onAgentStatus(self::HOST, PeerAgentStatusDTO::started('chat', '7', 4));
        self::assertSame([['chat', '7', self::HOST]], $sink->placed);
    }

    public function testNoRemainingNodeReportsARefusalAfterStop(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        $mesh->capabilities = [];

        $placement->onAgentStatus(self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));

        self::assertNull($placement->registry()->get('chat:7'));
        self::assertSame([['chat', '7', 'no capable node is available']], $sink->notPlaced);
    }

    public function testNewHostFailureReportsTheReason(): void
    {
        [$placement, , $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        $placement->onAgentStatus(self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::failed('chat', '7', 'start refused'));

        self::assertSame([['chat', '7', 'start refused']], $sink->notPlaced);
    }

    public function testLocalTargetWaitsForTheWorkerStartFact(): void
    {
        [$placement, $mesh, $sink] = $this->fixture();
        $mesh->capabilities['leader'] = [self::SLOTS];
        $placement->restartElsewhere('chat', '7', self::HOST, 'leader');

        $placement->onAgentStatus(self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));
        self::assertSame([], $sink->placed);
        self::assertSame(PlacementState::Started, $placement->registry()->get('chat:7')?->state);

        $placement->noteAgentStarted('chat', '7');
        self::assertSame([['chat', '7', 'leader']], $sink->placed);
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

        $placement->onAgentStatus(self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));

        self::assertSame([], $mesh->sent);
        self::assertSame([], $sink->placed);
    }

    public function testOldHostLeavingAfterStopDoesNotCancelTheNewPlacement(): void
    {
        [$placement, , $sink] = $this->fixture();
        $placement->restartElsewhere('chat', '7', self::HOST, self::TARGET);
        $placement->onAgentStatus(self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));

        $placement->noteNodeOffline(self::HOST, 100.0);
        self::assertSame([], $sink->notPlaced);

        $placement->onAgentStatus(self::TARGET, PeerAgentStatusDTO::started('chat', '7', 3));
        self::assertSame([['chat', '7', self::TARGET]], $sink->placed);
    }

    public function testInPlaceRestartRetainsPlacementAcrossOldStopAndReportsFailure(): void
    {
        $mesh = new RestartTestMesh([self::HOST => [self::SLOTS]]);
        $placement = new ClusterPlacement(self::HOST, $mesh, new RestartTestExecutor());
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('chat', '7'));
        $mesh->sent = [];

        $placement->beginInPlaceRestart('chat', '7');
        $placement->noteAgentStopped('chat', '7');
        self::assertSame([], $mesh->sent);
        $placement->onPlacementQuery('leader');
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

        $placement->onAgentStatus(self::HOST, PeerAgentStatusDTO::stopped('chat', '7'));

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
        $placement->registry()->put(new PlacementRecord('chat', '7', self::HOST, PlacementState::Started));

        return [$placement, $mesh, $sink];
    }
}

/** Peer links and capabilities mutable at the stop boundary. */
final class RestartTestMesh implements PlacementMesh
{
    /** @var list<array{string, PeerDTO}> */
    public array $sent = [];

    /** @var list<string> Nodes whose link has gone silent */
    public array $silent = [];

    /** @param array<string, list<string>> $capabilities Node capabilities */
    public function __construct(public array $capabilities)
    {
    }

    public function sendToNode(string $nodeId, PeerDTO $frame): bool
    {
        $this->sent[] = [$nodeId, $frame];
        return !in_array($nodeId, $this->silent, true);
    }

    public function broadcastToNodes(PeerDTO $frame): void
    {
    }

    public function nodeCapabilities(string $nodeId): ?array
    {
        return $this->capabilities[$nodeId] ?? null;
    }

    public function onlineNodeIds(): array
    {
        return array_keys($this->capabilities);
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

    public function executePlacement(string $agentType, ?string $agentIndex): ?int
    {
        return 1;
    }

    public function placedWorkerId(string $agentType, ?string $agentIndex): ?int
    {
        return 1;
    }

    public function revokePlacement(string $agentType, ?string $agentIndex): void
    {
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

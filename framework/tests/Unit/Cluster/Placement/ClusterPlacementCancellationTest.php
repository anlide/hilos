<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRefusal;
use Hilos\Cluster\Placement\PlacementRestartSink;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Cluster\PlacementVerdictSink;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Cancellation races shorter than a stand tick, driven without processes or wall-clock waits. */
final class ClusterPlacementCancellationTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$cluster = null;
        Logger::resetLogFile();
        parent::tearDown();
    }

    /** @return iterable<string, array{PlacementState, ?string}> Waiting states and optional history */
    public static function waitingRecords(): iterable
    {
        foreach ([PlacementState::Failed, PlacementState::Unplaced] as $state) {
            yield $state->value . ' without host' => [$state, null];
            yield $state->value . ' with history' => [$state, 'old'];
        }
    }

    /**
     * @param PlacementState $state Waiting state being canceled
     * @param ?string $previousNode Last attempted host
     */
    #[DataProvider('waitingRecords')]
    public function testWaitingCancellationNeedsNoAddresseeAndCannotRetry(PlacementState $state, ?string $previousNode): void
    {
        [$placement, $mesh, $executor] = $this->leader();
        $placement->onAgentStatus('old', PeerAgentStatusDTO::failed('render', '7', 'no worker'));
        $placement->registry()->put(new PlacementRecord(
            'render', '7', $previousNode, $state, 'no room', [PlacementRefusal::noOnlineNodes()],
        ));
        $placement->tick(1000.0);
        $mesh->sent = [];

        $placement->stopAgentOnNode('render', '7', null);
        $this->assertTombstone($placement);
        $this->assertSame([], $mesh->sent, 'Null never becomes a stop-frame addressee');
        $this->assertSame([], $placement->placementRefusals());
        $mesh->capabilities['leader'] = ['slots=10'];
        foreach ([1001.0, 1005.0, 1010.0, 1100.0] as $now) {
            $placement->noteNodeOnline('leader', $now);
            $placement->tick($now);
        }
        $this->assertSame([], $executor->executed);
        $this->assertSame([], $mesh->frames(PeerPlaceAgentDTO::class));
        $this->assertTombstone($placement);
    }

    /** @return iterable<string, array{string, bool}> Local/remote and placing/started */
    public static function liveRecords(): iterable
    {
        foreach (['leader', 'old'] as $node) {
            yield $node . ' placing' => [$node, true];
            yield $node . ' started' => [$node, false];
        }
    }

    /**
     * @param string $node Host being stopped
     * @param bool $placing Whether start has not finished
     */
    #[DataProvider('liveRecords')]
    public function testLiveCancellationSurvivesLocalCallbacksAndRemoteAcknowledgement(string $node, bool $placing): void
    {
        [$placement, $mesh, $executor] = $this->leader();
        $executor->waiting = $placing;
        $placement->placeAgentOnNode('render', '7', $node);
        if (!$placing && $node !== 'leader') {
            $placement->onAgentStatus($node, PeerAgentStatusDTO::started('render', '7', 1));
        }
        $executor->onRevoke = function () use ($placement): void {
            $this->assertTombstone($placement, 'leader');
            $placement->noteAgentStopped('render', '7');
            $placement->noteAgentStartFailed('render', '7', 'canceled while waiting');
            $this->assertTombstone($placement, 'leader');
        };
        $mesh->sent = [];

        $placement->stopAgentOnNode('render', '7', $node);

        if ($node === 'leader') {
            $this->assertSame([['render', '7']], $executor->revoked);
            $this->assertSame([], $mesh->frames(PeerStopAgentDTO::class));
        } else {
            $this->assertTombstone($placement, 'old');
            $this->assertCount(1, $mesh->frames(PeerStopAgentDTO::class));
            $placement->onAgentStatus('old', PeerAgentStatusDTO::stopped('render', '7'));
        }
        $this->assertTombstone($placement);
        $placement->onPlacementQuery('asker');
        $reports = $mesh->frames(PeerPlacementReportDTO::class);
        $this->assertSame([], $reports[0]->agents);
        $placement->tick(1100.0);
        $this->assertTombstone($placement);
    }

    public function testCancellationAnswersOldLocalAndRemoteWaitersBeforeRevoke(): void
    {
        [$placement, $mesh, $executor, $sink] = $this->leader();
        $executor->waiting = true;
        $placement->placeAgentOnNode('render', '7', 'leader');
        $placement->requirePlacement('render', '7');
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '7'));
        $executor->onRevoke = function () use ($placement, $mesh, $sink): void {
            $this->assertSame([['render', '7', 'failed: placement canceled']], $sink->notPlaced);
            $verdicts = $mesh->frames(PeerPlacementVerdictDTO::class);
            $this->assertCount(1, $verdicts);
            $this->assertSame(PlacementState::Failed, $verdicts[0]->state);
            $this->assertSame('placement canceled', $verdicts[0]->reason);
            $this->assertSame([], $verdicts[0]->refusals);
            $this->assertNull($verdicts[0]->nodeId);
            $placement->noteAgentStartFailed('render', '7', 'old start failed');
        };

        $placement->stopAgentOnNode('render', '7', 'leader');
        $placement->noteAgentStartFailed('render', '7', 'late failure');

        $this->assertTombstone($placement);
        $this->assertCount(1, $sink->notPlaced);
        $this->assertCount(1, $mesh->frames(PeerPlacementVerdictDTO::class));
    }

    public function testCancelingRebuildHeldDemandAnswersItAndDoesNotStartItWhenRebuildSettles(): void
    {
        [$placement, $mesh, , $sink] = $this->leader(settled: false);
        $placement->requirePlacement('render', '7');
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '7'));
        $placement->stopAgentOnNode('render', '7', null);
        $placement->onPlacementReport('old', new PeerPlacementReportDTO([]));
        $placement->onPlacementReport('new', new PeerPlacementReportDTO([]));
        $placement->tick(1100.0);

        $this->assertSame([['render', '7', 'failed: placement canceled']], $sink->notPlaced);
        $this->assertCount(1, $mesh->frames(PeerPlacementVerdictDTO::class));
        $this->assertSame([], $mesh->frames(PeerPlaceAgentDTO::class));
        $this->assertTombstone($placement);
    }

    public function testNewRequestWaitsForTheRightStopAndOldStatusesCannotEraseTheNewHost(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->placeAgentOnNode('render', '7', 'old');
        $placement->onPlacementRequest('old-asker', new PeerPlacementRequestDTO('render', '7'));
        $placement->stopAgentOnNode('render', '7', 'old');
        $mesh->capabilities['old'] = [];
        $mesh->sent = [];

        $placement->onPlacementRequest('new-asker', new PeerPlacementRequestDTO('render', '7'));
        $placement->onAgentStatus('unrelated', PeerAgentStatusDTO::stopped('render', '7'));
        $placement->onAgentStatus('old', PeerAgentStatusDTO::failed('render', '7', 'late failure'));
        $placement->tick(1001.0);
        $this->assertSame([], $mesh->frames(PeerPlaceAgentDTO::class));
        $this->assertSame([], $mesh->frames(PeerPlacementVerdictDTO::class));
        $this->assertTombstone($placement, 'old');

        $placement->onAgentStatus('old', PeerAgentStatusDTO::stopped('render', '7'));
        $this->assertCount(1, $mesh->frames(PeerPlaceAgentDTO::class));
        $fresh = $placement->registry()->get('render:7');
        $this->assertSame('new', $fresh?->nodeId);
        $this->assertSame(PlacementState::Placing, $fresh?->state);

        $placement->onAgentStatus('old', PeerAgentStatusDTO::stopped('render', '7'));
        $placement->onAgentStatus('old', PeerAgentStatusDTO::failed('render', '7', 'late failure'));
        $this->assertSame($fresh, $placement->registry()->get('render:7'));
        $placement->onAgentStatus('new', PeerAgentStatusDTO::started('render', '7', 1));
        $placement->onPlacementRequest('new-asker', new PeerPlacementRequestDTO('render', '7'));
        $verdicts = $mesh->frames(PeerPlacementVerdictDTO::class);
        $this->assertCount(1, $verdicts);
        $this->assertSame(PlacementState::Started, $verdicts[0]->state);
        $this->assertSame('new', $verdicts[0]->nodeId);
    }

    public function testNewWaitersStillGetAFailedVerdictIfNothingFitsAfterAcknowledgement(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->placeAgentOnNode('render', '7', 'old');
        $placement->onPlacementRequest('same-asker', new PeerPlacementRequestDTO('render', '7'));
        $placement->stopAgentOnNode('render', '7', 'old');
        $placement->onPlacementRequest('same-asker', new PeerPlacementRequestDTO('render', '7'));
        $mesh->capabilities = ['leader' => [], 'old' => [], 'new' => []];
        $mesh->onSend = static function (string $nodeId, PeerDTO $frame) use ($placement): void {
            if ($frame instanceof PeerPlacementQueryDTO) {
                $placement->onAgentStatus($nodeId, PeerAgentStatusDTO::stopped('render', '7'));
            }
        };
        $placement->tick(1001.0);
        $placement->tick(1017.0);

        $verdicts = $mesh->frames(PeerPlacementVerdictDTO::class);
        $this->assertCount(2, $verdicts);
        $this->assertSame('placement canceled', $verdicts[0]->reason);
        $this->assertSame(PlacementState::Unplaced, $verdicts[1]->state);
        $this->assertNotEmpty($verdicts[1]->refusals);
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:7')?->state);
        $waiting = $placement->registry()->get('render:7');
        $diagnosis = $placement->placementRefusals();
        $revision = $placement->registry()->revision();
        $placement->tick(1018.0);
        $placement->onAgentStatus('old', PeerAgentStatusDTO::stopped('render', '7'));
        $this->assertSame($waiting, $placement->registry()->get('render:7'));
        $this->assertSame($diagnosis, $placement->placementRefusals());
        $this->assertSame($revision, $placement->registry()->revision());
        $mesh->capabilities['leader'] = ['slots=10'];
        $placement->tick(1021.999);
        $this->assertSame($waiting, $placement->registry()->get('render:7'));
        $placement->tick(1022.0);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:7')?->state);
        $this->assertSame([], $placement->placementRefusals());
    }

    public function testNamedPlacementWaitsForStopAndKeepsItsTargetWhenRequestsJoin(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->placeAgentOnNode('render', '7', 'old');
        $placement->stopAgentOnNode('render', '7', 'old');
        $mesh->sent = [];
        $placement->placeAgentOnNode('render', '7', 'leader');
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '7'));
        $this->assertTombstone($placement, 'old');
        $this->assertSame([], $mesh->sent);

        $placement->onAgentStatus('old', PeerAgentStatusDTO::stopped('render', '7'));
        $this->assertSame('leader', $placement->registry()->get('render:7')?->nodeId);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:7')?->state);
    }

    public function testExplicitBestFitPlacementAlsoWaitsForStopAcknowledgement(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->placeAgentOnNode('render', '7', 'old');
        $placement->stopAgentOnNode('render', '7', 'old');
        $mesh->sent = [];
        $this->assertNull($placement->placeAgentOnBestNode('render', '7'));
        $this->assertSame([], $mesh->sent);
        $placement->onAgentStatus('old', PeerAgentStatusDTO::stopped('render', '7'));
        $this->assertCount(1, $mesh->frames(PeerPlaceAgentDTO::class));
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:7')?->state);
    }

    public function testSynchronousLocalRevokeKeepsNewDemandSeparateFromCanceledWaiters(): void
    {
        [$placement, , $executor, $sink] = $this->leader();
        $placement->placeAgentOnNode('render', '7', 'leader');
        $executor->onRevoke = function () use ($placement, $executor): void {
            $placement->noteAgentStopped('render', '7');
            $placement->requirePlacement('render', '7');
            $placement->noteAgentStartFailed('render', '7', 'old failure');
            $this->assertCount(1, $executor->executed, 'New work waits until local revoke returns');
        };

        $placement->stopAgentOnNode('render', '7', 'leader');
        $this->assertSame([], $sink->notPlaced, 'The new waiter did not inherit the cancellation verdict');
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:7')?->state);
    }

    public function testLateStartsAndReportsRepeatStopWhileLateStopsAndFailuresStaySilent(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->stopAgentOnNode('render', '7', null);
        $mesh->sent = [];
        foreach (['old', 'new'] as $node) {
            $placement->onAgentStatus($node, PeerAgentStatusDTO::started('render', '7', 1));
            $placement->onPlacementReport($node, new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '7')]));
            $placement->onAgentStatus($node, PeerAgentStatusDTO::stopped('render', '7'));
            $placement->onAgentStatus($node, PeerAgentStatusDTO::failed('render', '7', 'late failure'));
        }
        $this->assertCount(4, $mesh->sent);
        $this->assertCount(4, $mesh->frames(PeerStopAgentDTO::class));
        $this->assertTombstone($placement);
        $this->assertSame([], $placement->placementRefusals());
    }

    public function testLateLocalStartIsRevokedWithoutRevivingTheCanceledRecord(): void
    {
        [$placement, , $executor] = $this->leader();
        $placement->stopAgentOnNode('render', '7', null);
        $placement->noteAgentStarted('render', '7');
        $this->assertSame([['render', '7']], $executor->revoked);
        $this->assertTombstone($placement);
    }

    public function testStartedCallbackInsideLocalRevokeCannotReviveCanceledWork(): void
    {
        [$placement, , $executor] = $this->leader();
        $executor->waiting = true;
        $placement->placeAgentOnNode('render', '7', 'leader');
        $executor->onRevoke = static function () use ($placement, $executor): void {
            $executor->onRevoke = null;
            $placement->noteAgentStarted('render', '7');
        };

        $placement->stopAgentOnNode('render', '7', 'leader');

        $this->assertSame([['render', '7'], ['render', '7']], $executor->revoked);
        $this->assertTombstone($placement);
    }

    public function testLateStartsWhileAwaitingStopCannotAcknowledgeItOrReleaseDemand(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->placeAgentOnNode('render', '7', 'old');
        $placement->stopAgentOnNode('render', '7', 'old');
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '7'));
        $mesh->sent = [];
        $placement->onAgentStatus('old', PeerAgentStatusDTO::started('render', '7', 1));
        $placement->onPlacementReport('old', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '7')]));
        $this->assertTombstone($placement, 'old');
        $this->assertCount(2, $mesh->frames(PeerStopAgentDTO::class));
        $this->assertSame([], $mesh->frames(PeerPlaceAgentDTO::class));
    }

    public function testCancellationDoesNotTurnATruthSourceRefusalIntoARestartableTombstone(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->registry()->put(new PlacementRecord('render', '7', 'old', PlacementState::Refused));
        $placement->stopAgentOnNode('render', '7', 'old');
        $placement->onAgentStatus('old', PeerAgentStatusDTO::stopped('render', '7'));
        $mesh->sent = [];
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '7'));
        $this->assertSame(PlacementState::Refused, $placement->registry()->get('render:7')?->state);
        $verdicts = $mesh->frames(PeerPlacementVerdictDTO::class);
        $this->assertCount(1, $verdicts);
        $this->assertSame(PlacementState::Refused, $verdicts[0]->state);
        $this->assertSame([], $mesh->frames(PeerPlaceAgentDTO::class));
    }

    public function testOnlyAnAskedEmptyReportCanConfirmAMissingStopAcknowledgement(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->placeAgentOnNode('render', '7', 'old');
        $placement->stopAgentOnNode('render', '7', 'old');
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '7'));
        $placement->onPlacementReport('old', new PeerPlacementReportDTO([]));
        $this->assertTombstone($placement, 'old');
        $mesh->sent = [];
        $placement->tick(1001.0);
        $placement->tick(1017.0);
        $this->assertCount(1, $mesh->frames(PeerPlacementQueryDTO::class));
        $this->assertSame([], $mesh->frames(PeerPlaceAgentDTO::class));
        $placement->onPlacementReport('new', new PeerPlacementReportDTO([]));
        $this->assertTombstone($placement, 'old');
        $placement->onPlacementReport('old', new PeerPlacementReportDTO([]));
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:7')?->state);
        $this->assertCount(1, $mesh->frames(PeerPlaceAgentDTO::class));
    }

    public function testASecondCancellationDropsNewHeldDemandWithoutAcknowledgingTheOldStop(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->placeAgentOnNode('render', '7', 'old');
        $placement->stopAgentOnNode('render', '7', 'old');
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '7'));
        $placement->stopAgentOnNode('render', '7', null);
        $this->assertTombstone($placement, 'old');
        $mesh->sent = [];
        $placement->onAgentStatus('old', PeerAgentStatusDTO::stopped('render', '7'));
        $placement->tick(1100.0);
        $this->assertTombstone($placement);
        $this->assertSame([], $mesh->frames(PeerPlaceAgentDTO::class));
    }

    public function testExplicitCancellationEndsAPendingRestartWithoutStartingItsTarget(): void
    {
        [$placement, $mesh, , $sink] = $this->leader();
        $placement->placeAgentOnNode('render', '7', 'old');
        $placement->restartElsewhere('render', '7', 'old', 'new');
        $placement->stopAgentOnNode('render', '7', 'old');
        $mesh->sent = [];
        $placement->onAgentStatus('old', PeerAgentStatusDTO::stopped('render', '7'));
        $this->assertTombstone($placement);
        $this->assertSame([['render', '7', 'placement canceled']], $sink->notPlaced);
        $this->assertSame([], $mesh->sent);
    }

    public function testConfirmedTombstoneAllowsNewExplicitPlacementAndOrdinaryIdleStillForgets(): void
    {
        [$placement, , $executor] = $this->leader();
        $placement->stopAgentOnNode('render', '7', null);
        $placement->placeAgentOnNode('render', '7', 'leader');
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:7')?->state);
        $placement->noteAgentStopped('render', '7');
        $this->assertNull($placement->registry()->get('render:7'));
        $placement->placeAgentOnNode('render', '7', 'leader');
        $this->assertCount(2, $executor->executed);

        $placement->onAgentStatus('old', PeerAgentStatusDTO::started('render', '8', 1));
        $placement->onAgentStatus('old', PeerAgentStatusDTO::stopped('render', '8'));
        $this->assertNull($placement->registry()->get('render:8'));
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '8'));
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:8')?->state);
    }

    public function testCancelingOneWaitDoesNotDisturbOtherRetryDeadlines(): void
    {
        [$placement, $mesh, $executor] = $this->leader();
        $placement->registry()->put(new PlacementRecord('render', '7', null, PlacementState::Failed, 'no worker'));
        $placement->registry()->put(new PlacementRecord('render', '8', null, PlacementState::Failed, 'no worker'));
        $placement->tick(1000.0);
        $placement->stopAgentOnNode('render', '7', null);
        $mesh->capabilities = ['leader' => ['slots=10']];
        $placement->tick(1004.999);
        $this->assertSame([], $executor->executed);
        $placement->tick(1005.0);
        $this->assertSame([['render', '8']], $executor->executed);
        $this->assertTombstone($placement);
    }

    /**
     * @param bool $settled Whether all node reports have arrived
     * @return array{ClusterPlacement, CancellationMesh, CancellationExecutor, CancellationSink} Isolated ports and leader
     */
    private function leader(bool $settled = true): array
    {
        $mesh = new CancellationMesh();
        $executor = new CancellationExecutor();
        $sink = new CancellationSink();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerPlacementVerdictSink($sink);
        $placement = new ClusterPlacement('leader', $mesh, $executor, restartSink: $sink);
        $placement->onBecameLeader(1000.0);
        if ($settled) {
            $placement->onPlacementReport('old', new PeerPlacementReportDTO([]));
            $placement->onPlacementReport('new', new PeerPlacementReportDTO([]));
            $placement->tick(1000.0);
        }
        $mesh->sent = [];
        return [$placement, $mesh, $executor, $sink];
    }

    /**
     * @param ClusterPlacement $placement Coordinator being checked
     * @param ?string $node Host still owed a stop, or null once acknowledged
     */
    private function assertTombstone(ClusterPlacement $placement, ?string $node = null): void
    {
        $this->assertEquals(
            new PlacementRecord('render', '7', $node, PlacementState::Stopped),
            $placement->registry()->get('render:7'),
        );
    }
}

/** Mutable peer ports, with no transport or automatic replies. */
final class CancellationMesh implements PlacementMesh
{
    /** @var array<string, list<string>> Current advertised capacity */
    public array $capabilities = ['leader' => ['slots=10'], 'old' => ['slots=10'], 'new' => ['slots=10']];

    /** @var list<array{string, PeerDTO}> Addressed frames in send order */
    public array $sent = [];

    /** @var ?Closure Synchronous peer reply driven inside the coordinator's tick */
    public ?Closure $onSend = null;

    public function sendToNode(string $nodeId, PeerDTO $frame): bool
    {
        $this->sent[] = [$nodeId, $frame];
        if ($this->onSend !== null) {
            ($this->onSend)($nodeId, $frame);
        }
        return true;
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

    /**
     * @template T of PeerDTO
     * @param class-string<T> $class Frame class to select
     * @return list<T> Frames of the requested class
     */
    public function frames(string $class): array
    {
        $frames = [];
        foreach ($this->sent as [, $frame]) {
            if ($frame instanceof $class) {
                $frames[] = $frame;
            }
        }
        return $frames;
    }
}

/** The revoke callback exposes the synchronous local race window. */
final class CancellationExecutor implements PlacementExecutor
{
    /** @var list<array{string, ?string}> Local starts */
    public array $executed = [];

    /** @var list<array{string, ?string}> Local revokes */
    public array $revoked = [];

    public bool $waiting = false;

    public ?Closure $onRevoke = null;

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
        $this->executed[] = [$agentType, $agentIndex];
        return $this->waiting ? null : 1;
    }

    public function placedWorkerId(string $agentType, ?string $agentIndex): ?int
    {
        return $this->waiting ? null : 1;
    }

    public function revokePlacement(string $agentType, ?string $agentIndex): void
    {
        $this->revoked[] = [$agentType, $agentIndex];
        if ($this->onRevoke !== null) {
            ($this->onRevoke)();
        }
    }
}

/** Captures both the local held-frame failure and a restart's final failure. */
final class CancellationSink implements PlacementVerdictSink, PlacementRestartSink
{
    /** @var list<array{string, ?string, string}> Negative results */
    public array $notPlaced = [];

    public function onAgentNotPlaced(string $agentType, ?string $agentIndex, string $reason): void
    {
        $this->notPlaced[] = [$agentType, $agentIndex, $reason];
    }

    public function onRestartPlaced(string $agentType, ?string $agentIndex, string $nodeId): void
    {
    }

    public function onRestartNotPlaced(string $agentType, ?string $agentIndex, string $reason): void
    {
        $this->notPlaced[] = [$agentType, $agentIndex, $reason];
    }
}

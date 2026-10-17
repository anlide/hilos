<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Cluster\Placement\BestFitPlacementPolicy;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementCandidate;
use Hilos\Cluster\Placement\PlacementPolicy;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRefusalCode;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Utils\Logger;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises the shared waiting queue through requests, registry outcomes and explicit tick times. */
final class ClusterPlacementRetryTest extends TestCase
{
    protected function tearDown(): void
    {
        Logger::resetLogFile();
        parent::tearDown();
    }

    public function testNewNoFitWorkIsOneWaitingRecordWithNoHostAndStructuralDiagnosis(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1'], now: microtime(true));
        $record = $placement->registry()->get('render:1');
        $this->assertNotNull($record);
        $this->assertSame(PlacementState::Unplaced, $record->state);
        $this->assertNull($record->nodeId);
        $this->assertSame('no capable and online node fits the agent', $record->reason);
        $this->assertSame(PlacementRefusalCode::NoDeclaredCapacity, $record->refusals[0]->reasonCode);
        $this->assertSame(1, $policy->attempts);

        $mesh->sent = [];
        foreach (['asker-a', 'asker-b', 'asker-a'] as $asker) {
            $placement->onPlacementRequest($asker, new PeerPlacementRequestDTO('render', '1'));
        }
        $this->assertSame(1, $policy->attempts, 'Different requesters share the same interval');
        $this->assertSame($record, $placement->registry()->get('render:1'));
        $this->assertCount(1, $placement->registry()->all());
        $this->assertCount(3, $mesh->sent, 'Each request gets the last negative verdict immediately');
        foreach ($mesh->sent as [, $verdict]) {
            $this->assertInstanceOf(PeerPlacementVerdictDTO::class, $verdict);
            $this->assertSame(PlacementState::Unplaced, $verdict->state);
            $this->assertSame($record->reason, $verdict->reason);
            $this->assertEquals($record->refusals, $verdict->refusals);
        }
    }

    public function testFailedRequestsFromDifferentNodesReceiveTheLastVerdictDuringTheSamePause(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1'], fail: true, now: microtime(true));
        $mesh->sent = [];
        foreach (['first', 'second'] as $asker) {
            $placement->onPlacementRequest($asker, new PeerPlacementRequestDTO('render', '1'));
        }
        $this->assertSame(1, $policy->attempts);
        $this->assertCount(2, $mesh->sent);
        foreach ($mesh->sent as [, $verdict]) {
            $this->assertInstanceOf(PeerPlacementVerdictDTO::class, $verdict);
            $this->assertSame(PlacementState::Failed, $verdict->state);
            $this->assertSame('policy failed before choosing a host', $verdict->reason);
            $this->assertNull($verdict->nodeId);
        }
    }

    public function testRoundRobinDoesNotStarveLateWaitersWhenEveryTickIsPastAllDeadlines(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1', '2', '3']);
        $placement->tick(1005.0);
        $this->assertSame(4, $policy->attempts);
        $mesh->capabilities['leader'] = ['slots=10'];
        $placement->tick(1010.0);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:2')?->state);
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:1')?->state);
        $placement->tick(1015.0);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:3')?->state);
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:1')?->state);
        $placement->tick(1020.0);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:1')?->state);
        $this->assertSame(7, $policy->attempts);
    }

    public function testPeriodicRetryUsesOnlyTheSuppliedTimeAndCannotWakeItself(): void
    {
        [$placement, , , $policy] = $this->waiting(['1']);
        $placement->tick(1004.999);
        $this->assertSame(1, $policy->attempts);
        $placement->tick(1005.0);
        $this->assertSame(2, $policy->attempts);
        $placement->tick(1005.0);
        $placement->tick(1009.999);
        $this->assertSame(2, $policy->attempts, 'The retry failure does not free live capacity');
        $placement->tick(1010.0);
        $this->assertSame(3, $policy->attempts);
    }

    /** @return iterable<string, array{bool, ?string}> Waiting outcomes with optional host history */
    public static function waitingStopOutcomes(): iterable
    {
        foreach ([false, true] as $failed) {
            yield ($failed ? 'failed' : 'unplaced') . ' without host' => [$failed, null];
            yield ($failed ? 'failed' : 'unplaced') . ' with history' => [$failed, 'reporter'];
        }
    }

    /**
     * @param bool $failed Whether the attempt failed before selecting a host
     * @param ?string $previousNode Historical attempted host
     */
    #[DataProvider('waitingStopOutcomes')]
    public function testLateStopPreservesWaitingDiagnosisAndItsOriginalRetryDeadline(bool $failed, ?string $previousNode): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1'], fail: $failed);
        $waiting = $placement->registry()->get('render:1');
        $this->assertNotNull($waiting);
        $waiting = new PlacementRecord('render', '1', $previousNode, $waiting->state, $waiting->reason, $waiting->refusals);
        $placement->registry()->put($waiting);
        $waiting = $placement->registry()->get('render:1');
        $diagnosis = $placement->placementRefusals();
        $revision = $placement->registry()->revision();
        $placement->tick(1002.0);

        $placement->onAgentStatus('reporter', PeerAgentStatusDTO::stopped('render', '1'));
        $placement->onAgentStatus('reporter', PeerAgentStatusDTO::stopped('render', '1'));

        $this->assertSame($waiting, $placement->registry()->get('render:1'));
        $this->assertSame($diagnosis, $placement->placementRefusals());
        $this->assertSame($revision, $placement->registry()->revision());
        $mesh->capabilities['leader'] = ['slots=10'];
        $placement->tick(1004.999);
        $this->assertSame(1, $policy->attempts);
        $placement->tick(1005.0);
        $this->assertSame(2, $policy->attempts);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:1')?->state);
        $this->assertSame([], $placement->placementRefusals());
    }

    public function testSingletonAndIndexedWaitsUseTheSameBoundedFairPass(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting([null, '1', '2']);
        $mesh->capabilities['leader'] = ['slots=10'];
        $placement->tick(1005.0);
        $this->assertSame(4, $policy->attempts);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render')?->state);
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:1')?->state);
        $placement->tick(1005.0);
        $this->assertSame(5, $policy->attempts);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:1')?->state);
        $placement->tick(1005.0);
        $this->assertSame(6, $policy->attempts);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:2')?->state);
        $placement->tick(1100.0);
        $this->assertSame(6, $policy->attempts, 'Success removes the schedule');
    }

    public function testAnExceptionInTheFirstWaitDoesNotStarveLaterAgents(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1', '2', '3']);
        $mesh->capabilities['leader'] = ['slots=10'];
        $policy->throwNext = true;
        $placement->tick(1005.0);
        $failed = $placement->registry()->get('render:1');
        $this->assertSame(PlacementState::Failed, $failed?->state);
        $this->assertNull($failed?->nodeId);
        $this->assertSame('policy failed before choosing a host', $failed?->reason);
        $placement->tick(1005.0);
        $placement->tick(1005.0);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:2')?->state);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:3')?->state);
        $this->assertSame(6, $policy->attempts);
        $placement->tick(1009.999);
        $this->assertSame(6, $policy->attempts);
        $placement->tick(1010.0);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:1')?->state);
    }

    public function testJoinOnlyWakesUnplacedAndDoesNoPlacementInTheCallback(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1']);
        $mesh->capabilities['leader'] = ['slots=10'];
        $placement->noteNodeOnline('leader', 1001.0);
        $this->assertSame(1, $policy->attempts);
        $placement->tick(1001.0);
        $this->assertSame(2, $policy->attempts);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:1')?->state);
    }

    public function testFailedKeepsFiveSecondsAcrossJoinAndCapacityRelease(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1'], fail: true);
        $this->assertSame(PlacementState::Failed, $placement->registry()->get('render:1')?->state);
        $mesh->capabilities['leader'] = ['slots=10'];
        $placement->registry()->put(new PlacementRecord('other', null, 'leader', PlacementState::Started));
        $placement->registry()->forget('other');
        $placement->noteNodeOnline('leader', 1001.0);
        $placement->tick(1001.0);
        $placement->tick(1004.999);
        $this->assertSame(1, $policy->attempts);
        $placement->tick(1005.0);
        $this->assertSame(2, $policy->attempts);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:1')?->state);
    }

    public function testReleasingAReservationWakesUnplacedBeforeThePeriodicDeadline(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1']);
        $mesh->capabilities['leader'] = ['slots=1'];
        $placement->registry()->put(new PlacementRecord('other', null, 'leader', PlacementState::Placing));
        $placement->tick(1001.0);
        $this->assertSame(1, $policy->attempts, 'Adding occupancy is not a release');
        $placement->registry()->put(new PlacementRecord('other', null, 'leader', PlacementState::Stopped));
        $placement->tick(1001.0);
        $this->assertSame(2, $policy->attempts);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:1')?->state);
    }

    public function testARequestAfterTheSharedDeadlineUsesTheSameRetryAndSuccessPath(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1']);
        $mesh->capabilities['leader'] = ['slots=10'];
        $placement->onPlacementRequest('another', new PeerPlacementRequestDTO('render', '1'));
        $this->assertSame(2, $policy->attempts, 'The wall time of this external request is past the supplied deadline');
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:1')?->state);
        $placement->tick(1100.0);
        $this->assertSame(2, $policy->attempts);
    }

    public function testRefusedAndExplicitStopRemoveWaitingWork(): void
    {
        [$placement, , , $policy] = $this->waiting(['1', '2']);
        $placement->refusePlacement('render', '1', 'leader');
        $placement->stopAgentOnNode('render', '2', 'leader');
        $placement->tick(1100.0);
        $this->assertSame(2, $policy->attempts);
        $this->assertSame(PlacementState::Refused, $placement->registry()->get('render:1')?->state);
        $this->assertEquals(
            new PlacementRecord('render', '2', null, PlacementState::Stopped),
            $placement->registry()->get('render:2'),
        );
    }

    public function testLeadershipLossStopsTheOldSchedule(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1']);
        $mesh->capabilities['leader'] = ['slots=10'];
        $placement->onLostLeadership();
        $placement->noteNodeOnline('leader', 1001.0);
        $placement->tick(1100.0);
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '1'));
        $this->assertSame(1, $policy->attempts);
    }

    public function testQuorumLossStopsRetriesEvenWhenTheLeaderHostsNothing(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1']);
        $placement->noteQuorumLost(1001.0);
        $mesh->capabilities['leader'] = ['slots=10'];
        $placement->tick(1100.0);
        $this->assertSame(1, $policy->attempts);
    }

    public function testWaitingIsHeldBehindTheRebuildBarrierAndNoFollowerRetries(): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], online: ['leader', 'unreported']);
        $policy = new RetryTestPolicy();
        $placement = new ClusterPlacement('leader', $mesh, new FakePlacementExecutor(), policy: $policy);
        $placement->onBecameLeader(1000.0);
        $placement->registry()->put(new PlacementRecord('render', '1', null, PlacementState::Unplaced, 'no nodes'));
        $placement->noteNodeOnline('leader', 1000.0);
        $placement->tick(1010.0);
        $this->assertSame(0, $policy->attempts, 'Rebuild must settle before queued placement');
        $placement->onPlacementReport('unreported', new PeerPlacementReportDTO([]));
        $placement->tick(1010.0);
        $this->assertSame(1, $policy->attempts);

        $follower = new ClusterPlacement('leader', $mesh, new FakePlacementExecutor(), policy: $policy);
        $follower->registry()->put(new PlacementRecord('render', '2', null, PlacementState::Failed, 'no worker'));
        $follower->tick(1010.0);
        $follower->noteNodeOnline('leader', 1011.0);
        $follower->tick(1100.0);
        $this->assertSame(1, $policy->attempts, 'A follower has no retry authority');
    }

    public function testAQueuedRemotePlacementWaitsForItsStatusAndIsNeverRetriedWhilePlacing(): void
    {
        [$placement, $mesh, , $policy] = $this->waiting(['1']);
        $mesh->capabilities['reporter'] = ['slots=1'];
        $placement->tick(1005.0);
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:1')?->state);
        $placement->tick(1010.0);
        $this->assertSame(2, $policy->attempts);
        $placement->onAgentStatus('reporter', PeerAgentStatusDTO::started('render', '1', 1));
        $placement->tick(1100.0);
        $this->assertSame(2, $policy->attempts);
    }

    public function testChangedDiagnosisDoesNotWakeTheQueueOrDuplicateItsEntryLog(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'hil446-');
        Logger::setLogFile($log);
        [$placement, $mesh, , $policy] = $this->waiting(['1']);
        $mesh->capabilities['leader'] = ['slots=0'];
        $placement->tick(1005.0);
        $this->assertSame(PlacementRefusalCode::InsufficientCapacity, $placement->registry()->get('render:1')?->refusals[0]->reasonCode);
        $placement->tick(1005.0);
        $this->assertSame(2, $policy->attempts);
        $lines = file_get_contents($log);
        $this->assertSame(1, substr_count($lines, "Placement waiting: 'render:1'"));
        $this->assertSame(1, substr_count($lines, "Placement retry: 'render:1' trigger=periodic"));
        $mesh->capabilities['leader'] = ['slots=1'];
        $placement->noteNodeOnline('leader', 1006.0);
        $placement->tick(1006.0);
        $lines = file_get_contents($log);
        $this->assertSame(1, substr_count($lines, "Placement resumed: 'render:1' on 'leader'"));
        unlink($log);
    }

    /**
     * The initial requests are held behind a real rebuild, then executed inside tick(1000).
     * This gives every deadline the same explicit origin without sleeps or private time edits.
     *
     * @param list<?string> $indexes Agent indexes, including singleton null
     * @param bool $fail Whether the first selection throws before any host is chosen
     * @param float $now Explicit origin of the initial attempts
     * @return array{ClusterPlacement, FakePlacementMesh, FakePlacementExecutor, RetryTestPolicy} Driven coordinator and ports
     */
    private function waiting(array $indexes, bool $fail = false, float $now = 1000.0): array
    {
        $mesh = new FakePlacementMesh(['leader' => [], 'reporter' => []], linked: ['reporter'], online: ['leader', 'reporter']);
        $executor = new FakePlacementExecutor();
        $policy = new RetryTestPolicy();
        $policy->throwNext = $fail;
        $placement = new ClusterPlacement('leader', $mesh, $executor, policy: $policy);
        $placement->onBecameLeader($now);
        foreach ($indexes as $index) {
            $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', $index));
        }
        $placement->onPlacementReport('reporter', new PeerPlacementReportDTO([]));
        $placement->tick($now);

        return [$placement, $mesh, $executor, $policy];
    }
}

/** Counts actual policy attempts independently of the retry implementation. */
final class RetryTestPolicy implements PlacementPolicy
{
    public int $attempts = 0;

    public bool $throwNext = false;

    /**
     * @param list<string> $requiredTags Required tags
     * @param ResourceProfile $cost Charged cost
     * @param array<string, PlacementCandidate> $candidates Current candidates
     * @return ?string Best fit, or null
     * @throws LogicException When this attempt deliberately fails before choosing
     */
    public function selectNode(array $requiredTags, ResourceProfile $cost, array $candidates): ?string
    {
        $this->attempts++;
        if ($this->throwNext) {
            $this->throwNext = false;
            throw new LogicException('policy failed before choosing a host');
        }
        return new BestFitPlacementPolicy()->selectNode($requiredTags, $cost, $candidates);
    }
}

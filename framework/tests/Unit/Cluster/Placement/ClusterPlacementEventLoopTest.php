<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Closure;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReleaseDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Placement\PlacementSnapshot;
use Hilos\Hilos;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementAcknowledgement;
use Hilos\Cluster\Placement\PlacementDuty;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\PlacementTimer;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WeakReference;

/** Actual native deadlines and guarded portions; no production span is waited out. */
final class ClusterPlacementEventLoopTest extends TestCase
{
    private EventLoop $loop;

    /** @var list<ClusterPlacement> Owners detached before native base cleanup */
    private array $owners = [];

    /** @return iterable<string, array{PlacementState}> Both acknowledgements share the two-stage policy */
    public static function acknowledgementStates(): iterable
    {
        yield 'placement' => [PlacementState::Placing];
        yield 'stop' => [PlacementState::Stopped];
    }

    /** @param PlacementState $state Original live acknowledgement */
    #[DataProvider('acknowledgementStates')]
    public function testDueAcknowledgementOnlyAsksOnceAndThenSleeps(PlacementState $state): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->registry()->put(new PlacementRecord('render', '1', 'host', $state));
        $placement->tick(hrtime(true) / 1e9 - 100.0);
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $mesh->sent = [];
        $this->loop->tick();
        $this->assertSame([], $mesh->sent, 'The native callback may only record readiness');
        $placement->dispatchReady();
        $this->assertCount(1, $mesh->sent);
        $this->assertInstanceOf(PeerPlacementQueryDTO::class, $mesh->sent[0][1]);
        $this->assertSame($state, $placement->registry()->get('render:1')?->state, 'A query is no proof of missing work');
        for ($pass = 0; $pass < 4; ++$pass) {
            $this->loop->tick();
            $placement->dispatchReady();
        }
        $this->assertCount(1, $mesh->sent);
        $this->assertFalse($placement->hasReadyWork());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertNull($this->timer($placement, PlacementDuty::Acknowledgement, 'render:1'));
    }

    public function testSynchronousQueryReportJudgesTheOldWaitWithoutCreditingSameHostReplacement(): void
    {
        [$placement, $mesh] = $this->leader();
        $mesh->online = ['host'];
        $placement->registry()->put(new PlacementRecord('render', '1', 'host', PlacementState::Placing));
        $placement->tick(hrtime(true) / 1e9 - 100.0);
        $old = $this->acknowledgement($placement, 'render:1');
        $mesh->send = static function (string $node, PeerDTO $frame) use ($placement): void {
            if ($frame instanceof PeerPlacementQueryDTO) {
                $placement->onPlacementReport($node, new PeerPlacementReportDTO([]));
            }
        };
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $this->loop->tick();
        $placement->dispatchReady();
        $placement->dispatchReady();
        $fresh = $this->acknowledgement($placement, 'render:1');
        $this->assertNotNull($fresh);
        $this->assertNotSame($old, $fresh);
        $this->assertFalse($fresh->asked, 'The old query cannot mark the replacement as asked after its callback');
        $this->assertSame('host', $placement->registry()->get('render:1')?->nodeId);
        $this->assertCount(1, array_filter($mesh->sent, static fn(array $sent): bool => $sent[1] instanceof PeerPlaceAgentDTO));
    }

    public function testExplicitStopHasItsOwnWaitEvenAtTheSameNodeAndEqualDue(): void
    {
        [$placement, $mesh] = $this->leader();
        $past = hrtime(true) / 1e9 - 100.0;
        $placement->registry()->put(new PlacementRecord('render', '1', 'host', PlacementState::Placing));
        $placement->tick($past);
        $old = $this->timer($placement, PlacementDuty::Acknowledgement, 'render:1');
        $placement->stopAgentOnNode('render', '1', 'host');
        $placement->tick($past);
        $fresh = $this->timer($placement, PlacementDuty::Acknowledgement, 'render:1');
        $this->assertNotSame($old, $fresh);
        $this->assertSame($old?->at, $fresh?->at);
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $mesh->sent = [];
        $this->loop->tick();
        $placement->dispatchReady();
        $placement->onPlacementReport('host', new PeerPlacementReportDTO([]));
        $this->assertSame(PlacementState::Stopped, $placement->registry()->get('render:1')?->state);
        $this->assertNull($placement->registry()->get('render:1')?->nodeId);
        $this->assertNull($this->acknowledgement($placement, 'render:1'));
    }

    public function testUnrelatedRegistryEditsDoNotRenewAFutureWaitOrCreateRunnableWork(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->attachEventLoop($this->loop);
        $placement->registry()->put(new PlacementRecord('render', '1', 'host', PlacementState::Placing));
        $placement->dispatchReady();
        $timer = $this->timer($placement, PlacementDuty::Acknowledgement, 'render:1');
        $handle = $timer?->handle;
        $due = $handle?->getDeadline();
        $mesh->sent = [];
        for ($pass = 0; $pass < 5; ++$pass) {
            $placement->registry()->put(new PlacementRecord('render', 'other', null, PlacementState::Stopped, (string)$pass));
            $placement->dispatchReady();
            $this->loop->tick();
            $placement->dispatchReady();
            $this->assertSame($timer, $this->timer($placement, PlacementDuty::Acknowledgement, 'render:1'));
            $this->assertSame($handle, $timer?->handle);
            $this->assertSame($due, $handle?->getDeadline());
        }
        $this->assertSame([], $mesh->sent);
        $this->assertFalse($placement->hasReadyWork());
    }

    public function testOverdueFailoverSleepsBehindAReportBarrierUntilActualSettlement(): void
    {
        $mesh = new NativePlacementMesh();
        $mesh->online = ['leader', 'reporter'];
        $executor = new NativePlacementExecutor();
        $placement = $this->owner(new ClusterPlacement('leader', $mesh, $executor));
        $past = hrtime(true) / 1e9 - 100.0;
        $placement->onBecameLeader($past, 2);
        $placement->registry()->put(new PlacementRecord('render', '1', 'lost', PlacementState::Started));
        $placement->noteNodeOffline('lost', $past);
        $placement->attachEventLoop($this->loop);
        for ($pass = 0; $pass < 4; ++$pass) {
            $this->loop->tick();
            $placement->dispatchReady();
        }
        $this->assertSame([], $executor->executed);
        $this->assertFalse($placement->hasReadyWork(), 'A past failover behind a null report wait is dormant');
        $placement->onPlacementReport('reporter', new PeerPlacementReportDTO([]));
        $this->assertSame([], $executor->executed, 'The last report has not imported the complete original');
        $placement->dispatchReady();
        $this->assertSame([['render', '1']], $executor->executed);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:1')?->state);
    }

    public function testRebuildGraceKeepsTheOriginalOfflineObservationAcrossRepeatedLoss(): void
    {
        $mesh = new NativePlacementMesh();
        $mesh->online = ['leader', 'reporter'];
        $placement = $this->owner(new ClusterPlacement('leader', $mesh, new NativePlacementExecutor()));
        $past = hrtime(true) / 1e9 - 100.0;
        $placement->onBecameLeader($past, 1);
        $placement->noteNodeOffline('reporter', $past);
        $original = $this->timer($placement, PlacementDuty::Rebuild, 'reporter');
        $placement->noteNodeOffline('reporter', $past + 3.0);
        $this->assertSame($original, $this->timer($placement, PlacementDuty::Rebuild, 'reporter'));
        $this->assertSame($past + 8.0, $original?->at);
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $this->assertTrue(new ReflectionProperty($placement, 'rebuildPending')->getValue($placement));
        $this->loop->tick();
        $placement->dispatchReady();
        $this->assertFalse(new ReflectionProperty($placement, 'rebuildPending')->getValue($placement));
    }

    public function testManyDueRetriesMakeOnlyOneAttemptPerGuardedPassAndKeepTheirSuffix(): void
    {
        $mesh = new NativePlacementMesh();
        $mesh->capabilities = ['leader' => []];
        $policy = new RetryTestPolicy();
        $placement = $this->owner(new ClusterPlacement('leader', $mesh, new NativePlacementExecutor(), policy: $policy));
        $past = hrtime(true) / 1e9 - 100.0;
        $placement->onBecameLeader($past, 1);
        foreach (['1', '2', '3'] as $index) {
            $placement->registry()->put(new PlacementRecord('render', $index, null, PlacementState::Failed));
        }
        $placement->tick($past);
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $this->loop->tick();
        $policy->throwNext = true;
        $placement->dispatchReady();
        $this->assertSame(1, $policy->attempts);
        $this->assertTrue($placement->hasReadyWork(), 'Due peers continue without another input frame');
        $placement->dispatchReady();
        $this->assertSame(2, $policy->attempts);
        $placement->dispatchReady();
        $this->assertSame(3, $policy->attempts, 'An exception cannot starve the other ready waiters');
        for ($pass = 0; $pass < 3; ++$pass) {
            $this->loop->tick();
            $placement->dispatchReady();
        }
        $this->assertSame(3, $policy->attempts);
        $this->assertFalse($placement->hasReadyWork());
    }

    public function testCapacityAdvancesOnlyUnplacedAndKeepsTheOriginalFailedDeadline(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->attachEventLoop($this->loop);
        $placement->registry()->put(new PlacementRecord('render', 'failed', null, PlacementState::Failed));
        $placement->registry()->put(new PlacementRecord('render', 'unplaced', null, PlacementState::Unplaced));
        $placement->dispatchReady();
        $failed = $this->timer($placement, PlacementDuty::Retry, 'render:failed');
        $unplaced = $this->timer($placement, PlacementDuty::Retry, 'render:unplaced');
        $mesh->capabilities['leader'] = [];
        $placement->noteNodeOnline('leader', hrtime(true) / 1e9);
        $placement->dispatchReady();
        $this->assertSame($failed, $this->timer($placement, PlacementDuty::Retry, 'render:failed'));
        $this->assertLessThan($unplaced?->at, $this->timer($placement, PlacementDuty::Retry, 'render:unplaced')?->at);
        $this->assertGreaterThan(hrtime(true) / 1e9, $failed?->at);
    }

    /** @return iterable<string, array{bool}> Both existing Started source entrances */
    public static function resumedEntrances(): iterable
    {
        yield 'status reply' => [true];
        yield 'registry fact' => [false];
    }

    /** @param bool $status Whether Started arrives through the status handler */
    #[DataProvider('resumedEntrances')]
    public function testRemoteRetryKeepsItsResumeFactWhilePlacingSleeps(bool $status): void
    {
        $log = tempnam(sys_get_temp_dir(), 'placement-native-resume');
        Logger::setLogFile($log);
        try {
            [$placement, $mesh] = $this->leader();
            $mesh->online = ['host'];
            $placement->registry()->put(new PlacementRecord('render', 'resumed', null, PlacementState::Unplaced));
            $placement->tick(hrtime(true) / 1e9 - 100.0);
            $placement->attachEventLoop($this->loop);
            $placement->dispatchReady();
            $this->loop->tick();
            $placement->dispatchReady();
            $placement->dispatchReady();
            $this->loop->tick();
            $placement->dispatchReady();
            $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:resumed')?->state);
            $this->assertNull($this->timer($placement, PlacementDuty::Retry, 'render:resumed'));
            $this->assertFalse($placement->hasReadyWork(), 'A pending placement is an event-only wait');
            $resumed = "Placement resumed: 'render:resumed' on 'host'";
            $this->assertSame(0, substr_count(file_get_contents($log), $resumed));
            if ($status) {
                $placement->onAgentStatus('host', PeerAgentStatusDTO::started('render', 'resumed', 1));
            } else {
                $placement->registry()->put(new PlacementRecord('render', 'resumed', 'host', PlacementState::Started));
            }
            $placement->dispatchReady();
            $placement->onAgentStatus('host', PeerAgentStatusDTO::started('render', 'resumed', 1));
            $this->assertSame(1, substr_count(file_get_contents($log), $resumed), 'Started consumes the original resume fact once');
        } finally {
            Logger::resetLogFile();
            unlink($log);
        }
    }

    /** @return iterable<string, array{bool}> Both actual hosting roles owe the same fence ordering */
    public static function hostingRoles(): iterable
    {
        yield 'slave host' => [false];
        yield 'master host' => [true];
    }

    /** @param bool $master Whether the host led before losing quorum */
    #[DataProvider('hostingRoles')]
    public function testFenceRevokesAllWorkBeforeTheEmptyCompleteReportAndDoesNotRenew(bool $master): void
    {
        $mesh = new NativePlacementMesh();
        $mesh->capabilities['host'] = ['slots=100'];
        $executor = new NativePlacementExecutor();
        $placement = $this->owner(new ClusterPlacement('host', $mesh, $executor));
        if ($master) {
            $mesh->online = ['host'];
            $placement->onBecameLeader(hrtime(true) / 1e9, 1);
            $placement->placeAgentOnNode('render', '1', 'host');
        } else {
            $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1'));
        }
        $executor->waitsForWorker = true;
        if ($master) {
            $placement->placeAgentOnNode('render', '2', 'host');
        } else {
            $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '2'));
        }
        $past = hrtime(true) / 1e9 - 100.0;
        $placement->noteQuorumLost($past);
        $original = $this->timer($placement, PlacementDuty::SelfFence, 'host');
        $placement->noteQuorumLost($past + 2.0);
        $placement->noteNodeOnline('leader', $past + 3.0);
        $this->assertSame($past + 6.0, $original?->at);
        $this->assertSame($original, $this->timer($placement, PlacementDuty::SelfFence, 'host'));
        $mesh->broadcast = [];
        $mesh->broadcasting = function (PeerDTO $frame) use ($executor): void {
            if ($frame instanceof PeerPlacementReportDTO) {
                $this->assertCount(2, $executor->revoked, 'Every logical revoke precedes the complete empty report');
                $this->assertSame([], $frame->agents);
            }
        };
        $placement->attachEventLoop($this->loop);
        $this->loop->tick();
        $this->assertSame([], $executor->revoked, 'Native timer work never calls the executor');
        $placement->dispatchReady();
        $this->assertSame([['render', '1'], ['render', '2']], $executor->revoked);
        $this->assertCount(1, $mesh->broadcast);
    }

    public function testSynchronousRevokeCannotEraseNewAuthorityOrPublishAFalseEmptySet(): void
    {
        $mesh = new NativePlacementMesh();
        $executor = new NativePlacementExecutor();
        $placement = $this->owner(new ClusterPlacement('host', $mesh, $executor));
        $placement->onPlaceAgent('old', new PeerPlaceAgentDTO('render', '1'));
        $placement->onPlaceAgent('old', new PeerPlaceAgentDTO('render', '2'));
        $placement->noteQuorumLost(hrtime(true) / 1e9 - 100.0);
        $executor->revoking = static function () use ($placement, $executor): void {
            $executor->revoking = null;
            $placement->onPlaceAgent('new', new PeerPlaceAgentDTO('render', '1'));
            $placement->onPlaceAgent('new', new PeerPlaceAgentDTO('render', '2'));
        };
        $mesh->broadcast = [];
        $placement->attachEventLoop($this->loop);
        $this->loop->tick();
        $placement->dispatchReady();
        $this->assertSame('new', new ReflectionProperty($placement, 'placingLeaderId')->getValue($placement));
        $this->assertCount(2, new ReflectionProperty($placement, 'hosted')->getValue($placement));
        $this->assertSame([['render', '1']], $executor->revoked, 'The old suffix must not revoke a newly accepted incarnation');
        $this->assertSame([], $mesh->broadcast, 'No old empty complete report may describe the new hosted set');
    }

    public function testFiniteDirtyPrefixContinuesUnderNewFactsAndPublishesOnlyTheCompleteCohort(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $mesh->broadcast = [];
        for ($index = 0; $index < 70; ++$index) {
            $placement->registry()->put(new PlacementRecord('render', (string)$index, 'host', PlacementState::Placing));
        }
        $placement->dispatchReady();
        $this->assertTrue($placement->hasReadyWork());
        $this->assertSame([], $mesh->broadcast, 'A partial discovery cohort is not a complete publication');
        $first = $this->timer($placement, PlacementDuty::Acknowledgement, 'render:0');
        $placement->registry()->put(new PlacementRecord('render', '0', 'host', PlacementState::Placing, 'new diagnostic'));
        $placement->dispatchReady();
        $this->assertNotNull($this->timer($placement, PlacementDuty::Acknowledgement, 'render:63'));
        $this->assertNull($this->timer($placement, PlacementDuty::Acknowledgement, 'render:69'));
        $placement->dispatchReady();
        $this->assertSame($first, $this->timer($placement, PlacementDuty::Acknowledgement, 'render:0'));
        $this->assertNotNull($this->timer($placement, PlacementDuty::Acknowledgement, 'render:69'));
        $this->assertCount(1, $mesh->broadcast);
        $this->assertInstanceOf(PeerPlacementViewDTO::class, $mesh->broadcast[0]);
        $this->assertCount(70, $mesh->broadcast[0]->placementSnapshot->records);
        $placement->registry()->clear();
        for ($pass = 0; $pass < 3; ++$pass) {
            $placement->dispatchReady();
        }
        $this->assertCount(2, $mesh->broadcast);
        $this->assertSame([], $mesh->broadcast[1]->placementSnapshot->records);
        $this->assertNull($this->timer($placement, PlacementDuty::Acknowledgement, 'render:69'));
    }

    public function testDetachRebindRetainsDueAndRejectsAnEscapedOldCallback(): void
    {
        [$placement] = $this->leader();
        $placement->registry()->put(new PlacementRecord('render', '1', 'host', PlacementState::Placing));
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $timer = $this->timer($placement, PlacementDuty::Acknowledgement, 'render:1');
        $callback = new ReflectionProperty(EventLoopTimer::class, 'callback')->getValue($timer->handle);
        $due = $timer->at;
        $placement->detachEventLoop();
        $this->assertNull($timer->handle);
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $this->loop->tick();
        $placement->dispatchReady();
        $callback();
        $this->assertSame($due, $timer->handle?->getDeadline());
        $this->assertFalse($placement->hasReadyWork());
    }

    public function testNativeHandlesDoNotRetainAnAbandonedOwner(): void
    {
        $placement = new ClusterPlacement('host', new NativePlacementMesh(), new NativePlacementExecutor());
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1'));
        $placement->noteNodeOffline('leader', hrtime(true) / 1e9);
        $placement->attachEventLoop($this->loop);
        $weak = WeakReference::create($placement);
        unset($placement);
        gc_collect_cycles();
        $this->assertNull($weak->get(), 'Only process-local weak generation callbacks may reach a detached owner');
    }

    public function testAnEarlierReportCannotBorrowReentrantQuestionCredit(): void
    {
        [$placement, $mesh] = $this->leader();
        $past = hrtime(true) / 1e9 - 100.0;
        $placement->registry()->put(new PlacementRecord('render', 'missing', 'host', PlacementState::Placing));
        $placement->registry()->put(new PlacementRecord('render', 'wrong', 'elsewhere', PlacementState::Started));
        $placement->tick($past);
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $mesh->send = function (string $node, PeerDTO $frame) use ($placement): void {
            if ($frame instanceof PeerStopAgentDTO) {
                $this->loop->tick();
                $placement->dispatchReady();
            }
        };
        $placement->onPlacementReport('host', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', 'wrong')]));
        $this->assertTrue($this->acknowledgement($placement, 'render:missing')?->asked);
        $this->assertSame('host', $placement->registry()->get('render:missing')?->nodeId);
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:missing')?->state);
        $mesh->send = null;
        $placement->onPlacementReport('host', new PeerPlacementReportDTO([]));
        $this->assertSame('leader', $placement->registry()->get('render:missing')?->nodeId, 'A later report may judge that exact asked wait');
    }

    public function testSynchronousStartedStatusIsNotOverwrittenByTheReturningPlacementSend(): void
    {
        [$placement, $mesh] = $this->leader();
        $mesh->send = static function (string $node, PeerDTO $frame) use ($placement): void {
            if ($frame instanceof PeerPlaceAgentDTO) {
                $placement->onAgentStatus($node, PeerAgentStatusDTO::started($frame->agentType, $frame->agentIndex, 1));
            }
        };
        $placement->attachEventLoop($this->loop);
        $placement->placeAgentOnNode('render', 'sync', 'host');
        $placement->dispatchReady();
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:sync')?->state);
        $this->assertNull($this->acknowledgement($placement, 'render:sync'));
    }

    public function testNativeImportKeepsCancellationAheadOfOverdueFailoverAndHeldDemand(): void
    {
        $mesh = new NativePlacementMesh();
        $mesh->online = ['leader', 'reporter'];
        $executor = new NativePlacementExecutor();
        $placement = $this->owner(new ClusterPlacement('leader', $mesh, $executor));
        $past = hrtime(true) / 1e9 - 100.0;
        $placement->onBecameLeader($past, 2);
        $placement->registry()->put(new PlacementRecord('render', '1', 'lost', PlacementState::Started));
        $placement->noteNodeOffline('lost', $past);
        $placement->placeAgentOnNode('render', '1', 'leader');
        $placement->attachEventLoop($this->loop);
        $this->loop->tick();
        $placement->dispatchReady();
        $donor = new PlacementSnapshot('old', 1, 4, [new PlacementRecord('render', '1', null, PlacementState::Stopped)]);
        $placement->onPlacementReport('reporter', new PeerPlacementReportDTO([], $donor));
        $this->assertSame($donor, $placement->placementSnapshot(), 'The final report still exposes the retained complete donor');
        $this->assertSame([], $executor->executed);
        $placement->dispatchReady();
        // Explicit held demand waits for the inherited real host's stop, rather than bypassing it.
        $this->assertSame(PlacementState::Stopped, $placement->registry()->get('render:1')?->state);
        $this->assertSame('lost', $placement->registry()->get('render:1')?->nodeId);
        $this->assertSame([], $executor->executed);
        $this->assertNull($this->timer($placement, PlacementDuty::Failover, 'render:1'));
    }

    public function testCapacityFactAccountsForTheOriginalDirtySuffixWithoutBeingResetByLaterFacts(): void
    {
        [$placement] = $this->leader();
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        for ($index = 0; $index < 70; ++$index) {
            $placement->registry()->put(new PlacementRecord('render', (string)$index, null, PlacementState::Unplaced));
        }
        $eventAt = hrtime(true) / 1e9;
        $placement->noteNodeOnline('host', $eventAt);
        for ($pass = 0; $pass < 5; ++$pass) {
            $placement->dispatchReady();
            $placement->registry()->put(new PlacementRecord('chat', (string)$pass, null, PlacementState::Stopped));
            $placement->noteNodeOnline('host', $eventAt + 1.0);
        }
        $last = $this->timer($placement, PlacementDuty::Retry, 'render:69');
        $this->assertNotNull($last);
        $this->assertSame($eventAt, $last->at, 'The original event advances the original suffix despite follow-up facts');
    }

    public function testEarlyCandidateConfirmsOnIndependentAuthorityWithoutAnotherViewOrBrowserConsumer(): void
    {
        $previousCluster = Hilos::$cluster;
        try {
            $authority = new SnapshotTestLeadership(null, 1);
            Hilos::$cluster = new ClusterContext();
            Hilos::$cluster->registerLeadership($authority);
            $placement = $this->owner(new ClusterPlacement('follower', new NativePlacementMesh(), new NativePlacementExecutor()));
            $snapshot = new PlacementSnapshot('leader', 2, 3, []);
            $placement->attachEventLoop($this->loop);
            $placement->onPlacementView('leader', new PeerPlacementViewDTO('leader', [], $snapshot));
            $placement->reconcileAuthority();
            $placement->dispatchReady();
            $this->loop->tick();
            $placement->dispatchReady();
            $this->assertNull($placement->placementSnapshot());
            $this->assertFalse($placement->hasReadyWork());
            $authority->leader = 'leader';
            $authority->currentTerm = 2;
            $placement->reconcileAuthority();
            $this->assertTrue($placement->hasReadyWork());
            $placement->dispatchReady();
            $this->assertSame($snapshot, $placement->placementSnapshot());
            $this->assertSame([], $placement->registry()->all());
        } finally {
            Hilos::$cluster = $previousCluster;
        }
    }

    public function testRetryExecutorReentryCannotRunASecondAttemptInsideTheSameGuardedPass(): void
    {
        [$placement, , $executor] = $this->leader();
        $past = hrtime(true) / 1e9 - 100.0;
        foreach (['1', '2'] as $index) {
            $placement->registry()->put(new PlacementRecord('render', $index, null, PlacementState::Failed));
        }
        $placement->tick($past);
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $executor->executing = static function () use ($placement): void {
            $placement->dispatchReady();
        };
        $this->loop->tick();
        $placement->dispatchReady();
        $this->assertCount(1, $executor->executed);
        $placement->dispatchReady();
        $this->assertCount(2, $executor->executed);
    }

    /** @return iterable<string, array{string}> Actual facts that cancel a still-identified placing leader's loss */
    public static function fenceCancellations(): iterable
    {
        yield 'identified leader returns' => ['online'];
        yield 'another leader takes over' => ['query'];
        yield 'host wins leadership' => ['promotion'];
    }

    /** @param string $fact Independent cancellation source */
    #[DataProvider('fenceCancellations')]
    public function testAuthorityCancellationRetiresAnAlreadyReadyFence(string $fact): void
    {
        $mesh = new NativePlacementMesh();
        $executor = new NativePlacementExecutor();
        $placement = $this->owner(new ClusterPlacement('host', $mesh, $executor));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1'));
        $placement->noteNodeOffline('leader', hrtime(true) / 1e9 - 100.0);
        $placement->attachEventLoop($this->loop);
        $this->loop->tick();
        if ($fact === 'online') {
            $placement->noteNodeOnline('leader', hrtime(true) / 1e9);
        } elseif ($fact === 'query') {
            $placement->onPlacementQuery('new');
        } else {
            $mesh->online = ['host'];
            $placement->onBecameLeader(hrtime(true) / 1e9, 2);
        }
        $placement->dispatchReady();
        $this->assertSame([], $executor->revoked);
        $this->assertNull($this->timer($placement, PlacementDuty::SelfFence, 'host'));
    }

    public function testActualNativeArmFailureKeepsItsEventLoopException(): void
    {
        $placement = $this->owner(new ClusterPlacement('host', new NativePlacementMesh(), new NativePlacementExecutor()));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1'));
        $placement->attachEventLoop($this->loop);
        $this->expectException(EventLoopException::class);
        $placement->noteQuorumLost(NAN);
    }

    public function testUndeliveredPlacementCannotInventAReleasedReservation(): void
    {
        [$placement, $mesh] = $this->leader();
        $mesh->delivered = false;
        $before = $placement->registry()->capacityReleaseRevision();
        $placement->placeAgentOnNode('render', 'no-link', 'host');
        $this->assertSame(PlacementState::Failed, $placement->registry()->get('render:no-link')?->state);
        $this->assertSame($before, $placement->registry()->capacityReleaseRevision());
    }

    public function testHandshakeQueryReentryKeepsItsInitialViewAndSnapshotCoherent(): void
    {
        [$placement, $mesh] = $this->leader();
        $mesh->send = static function (string $node, PeerDTO $frame) use ($placement): void {
            if ($frame instanceof PeerPlacementQueryDTO) {
                $placement->registry()->put(new PlacementRecord('render', 'late', 'host', PlacementState::Started));
            }
        };
        $placement->onPeerHandshaked('newcomer');
        $view = $mesh->sent[1][1];
        $this->assertInstanceOf(PeerPlacementViewDTO::class, $view);
        $this->assertCount(1, $view->agents['host']);
        $this->assertCount(1, $view->placementSnapshot->records);
        $this->assertSame('render:late', $view->placementSnapshot->records[0]->agentId());
    }

    public function testOldPlacementCompletionCannotCancelTheReplacementLossAtAnEqualDue(): void
    {
        [$placement, $mesh] = $this->leader();
        $past = hrtime(true) / 1e9 - 100.0;
        $placement->registry()->put(new PlacementRecord('render', '1', 'host', PlacementState::Started));
        $placement->noteNodeOffline('host', $past);
        $placement->attachEventLoop($this->loop);
        $old = $this->timer($placement, PlacementDuty::Failover, 'render:1');
        $callback = new ReflectionProperty(EventLoopTimer::class, 'callback')->getValue($old->handle);
        $mesh->send = static function (string $node, PeerDTO $frame) use ($placement, $past): void {
            if ($frame instanceof PeerPlaceAgentDTO) {
                $placement->noteNodeOnline($node, $past);
                $placement->noteNodeOffline($node, $past);
            }
        };
        $placement->placeAgentOnNode('render', '1', 'host');
        $fresh = $this->timer($placement, PlacementDuty::Failover, 'render:1');
        $this->assertNotNull($fresh);
        $this->assertNotSame($old, $fresh);
        $this->assertSame($old->at, $fresh->at);
        $callback();
        $this->assertSame([], new ReflectionProperty($placement, 'readyTimers')->getValue($placement)[PlacementDuty::Failover->value] ?? []);
    }

    public function testAStartedExecutorReturnLosingAuthorityCannotLeaveUntrackedWork(): void
    {
        [$placement, , $executor] = $this->leader();
        $executor->executing = static function () use ($placement): void {
            $placement->noteQuorumLost(hrtime(true) / 1e9);
        };
        $placement->placeAgentOnNode('render', 'abandoned', 'leader');
        $this->assertNull($placement->registry()->get('render:abandoned'));
        $this->assertSame([['render', 'abandoned']], $executor->revoked);
        $this->assertSame([], new ReflectionProperty($placement, 'hosted')->getValue($placement));
    }

    public function testSynchronousTakeoverDuringLeadershipReleaseCannotBeForgottenByTheOlderQuorumLoss(): void
    {
        [$placement, $mesh] = $this->leader();
        $placement->placeAgentOnNode('render', '1', 'leader');
        $mesh->broadcasting = static function (PeerDTO $frame) use ($placement): void {
            if ($frame instanceof PeerPlacementReleaseDTO) {
                $placement->onPlacementQuery('new');
            }
        };
        $placement->attachEventLoop($this->loop);
        $placement->noteQuorumLost(hrtime(true) / 1e9 - 100.0);
        $this->loop->tick();
        $placement->dispatchReady();
        $this->assertSame('new', new ReflectionProperty($placement, 'placingLeaderId')->getValue($placement));
        $this->assertNull($this->timer($placement, PlacementDuty::SelfFence, 'leader'));
        $this->assertCount(1, new ReflectionProperty($placement, 'hosted')->getValue($placement));
    }

    public function testAnExecutingOldAckPrefixStopsAtRebindAndResumesOnTheNewNativeGeneration(): void
    {
        [$placement, $mesh] = $this->leader();
        $past = hrtime(true) / 1e9 - 100.0;
        foreach (['1', '2'] as $index) {
            $placement->registry()->put(new PlacementRecord('render', $index, 'host', PlacementState::Placing));
        }
        $placement->tick($past);
        $placement->attachEventLoop($this->loop);
        $placement->dispatchReady();
        $this->loop->tick();
        $mesh->sent = [];
        $mesh->send = function (string $node, PeerDTO $frame) use ($placement, $mesh): void {
            if ($frame instanceof PeerPlacementQueryDTO) {
                $mesh->send = null;
                $placement->detachEventLoop();
                $placement->attachEventLoop($this->loop);
            }
        };
        $placement->dispatchReady();
        $this->assertCount(1, $mesh->sent, 'The retired binding cannot execute the rest of its old prefix');
        $this->loop->tick();
        $placement->dispatchReady();
        $this->assertCount(2, $mesh->sent, 'The new binding retains the original overdue suffix');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = new EventLoop();
    }

    protected function tearDown(): void
    {
        foreach ($this->owners as $owner) {
            $owner->detachEventLoop();
        }
        $this->loop->cleanup();
        Logger::resetLogFile();
        parent::tearDown();
    }

    /** @return array{ClusterPlacement, NativePlacementMesh, NativePlacementExecutor} Unblocked original with real native lifetime */
    private function leader(): array
    {
        $mesh = new NativePlacementMesh();
        $executor = new NativePlacementExecutor();
        $placement = $this->owner(new ClusterPlacement('leader', $mesh, $executor));
        $placement->onBecameLeader(hrtime(true) / 1e9, 1);

        return [$placement, $mesh, $executor];
    }

    /**
     * @param ClusterPlacement $placement Owner whose lifetime belongs to this case
     * @return ClusterPlacement Same owner for setup expressions
     */
    private function owner(ClusterPlacement $placement): ClusterPlacement
    {
        $this->owners[] = $placement;

        return $placement;
    }

    /**
     * @param ClusterPlacement $placement Inspected owner
     * @param PlacementDuty $duty Addressed phase
     * @param string $key Exact node or agent
     * @return ?PlacementTimer Current logical incarnation
     */
    private function timer(ClusterPlacement $placement, PlacementDuty $duty, string $key): ?PlacementTimer
    {
        return new ReflectionProperty($placement, 'timers')->getValue($placement)[$duty->value][$key] ?? null;
    }

    /**
     * @param ClusterPlacement $placement Inspected owner
     * @param string $agentId Exact waiting agent
     * @return ?PlacementAcknowledgement Current request's report credit
     */
    private function acknowledgement(ClusterPlacement $placement, string $agentId): ?PlacementAcknowledgement
    {
        return new ReflectionProperty($placement, 'placementAckDeadlines')->getValue($placement)[$agentId] ?? null;
    }
}

/** Records port effects and optionally returns synchronous mesh facts on the same stack. */
final class NativePlacementMesh implements PlacementMesh
{
    /** @var array<string, list<string>> Advertised node capabilities */
    public array $capabilities = ['leader' => ['slots=100'], 'host' => ['slots=100']];

    /** @var list<string> Current online roster */
    public array $online = ['leader'];

    /** @var list<array{string, PeerDTO}> Addressed port calls */
    public array $sent = [];

    /** @var list<PeerDTO> Complete broadcasts */
    public array $broadcast = [];

    public ?Closure $send = null;

    public ?Closure $broadcasting = null;

    public bool $delivered = true;

    public function sendToNode(string $nodeId, PeerDTO $frame): bool
    {
        $this->sent[] = [$nodeId, $frame];
        $this->send?->__invoke($nodeId, $frame);

        return $this->delivered;
    }

    public function broadcastToNodes(PeerDTO $frame): void
    {
        $this->broadcast[] = $frame;
        $this->broadcasting?->__invoke($frame);
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

/** Executor facts remain distinct from physical worker stop acknowledgements. */
final class NativePlacementExecutor implements PlacementExecutor
{
    /** @var list<array{string, ?string}> Accepted starts */
    public array $executed = [];

    /** @var list<array{string, ?string}> Logical revocations */
    public array $revoked = [];

    public bool $waitsForWorker = false;

    public ?Closure $revoking = null;

    public ?Closure $executing = null;

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
        $this->executing?->__invoke($agentType, $agentIndex);

        return $this->waitsForWorker ? null : 1;
    }

    public function placedWorkerId(string $agentType, ?string $agentIndex): ?int
    {
        return $this->waitsForWorker ? null : 1;
    }

    public function revokePlacement(string $agentType, ?string $agentIndex): void
    {
        $this->revoked[] = [$agentType, $agentIndex];
        $this->revoking?->__invoke($agentType, $agentIndex);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Placement\PlacementId;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ConsensusInspection;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRecovery;
use Hilos\Cluster\Placement\PlacementRefusal;
use Hilos\Cluster\Placement\PlacementSnapshot;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Complete snapshots, fenced mirrors and inheritance through the real rebuild barrier. */
final class ClusterPlacementSnapshotTest extends TestCase
{
    private ?ClusterContext $previousCluster;

    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        Hilos::$cluster = null;
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, PlacementTestHilos::class);
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = $this->previousCluster;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
        parent::tearDown();
    }

    public function testDiagnosisTombstoneDeletionAndEmptyRegistryPublishWithoutAddressChanges(): void
    {
        $mesh = new FakePlacementMesh([]);
        $placement = new ClusterPlacement('leader', $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0, 7);
        $failed = new PlacementRecord('render', '9', null, PlacementState::Failed, 'first failure');
        $placement->registry()->put($failed);
        $placement->tick(1000.0);
        $first = $this->views($mesh)[0];
        self::assertSame([], $first->agents);
        self::assertSame(7, $first->placementSnapshot->term);
        self::assertEquals([$failed], $first->placementSnapshot->records);

        $diagnosed = new PlacementRecord('render', '9', null, PlacementState::Failed, 'second failure', [
            PlacementRefusal::noDeclaredCapacity('node-b'),
        ]);
        $placement->registry()->put($diagnosed);
        $placement->tick(1000.1);
        $second = $this->views($mesh)[1];
        self::assertSame([], $second->agents);
        self::assertGreaterThan($first->placementSnapshot->revision, $second->placementSnapshot->revision);
        self::assertEquals([$diagnosed], $second->placementSnapshot->records);
        self::assertSame('first failure', $first->placementSnapshot->records[0]->reason, 'Earlier versions are immutable');

        $placement->stopAgentOnNode('render', '9', null);
        $placement->tick(1000.2);
        $third = $this->views($mesh)[2];
        self::assertSame([], $third->agents);
        self::assertSame(PlacementState::Stopped, $third->placementSnapshot->records[0]->state);

        $placement->registry()->forget('render:9');
        $placement->tick(1000.3);
        $fourth = $this->views($mesh)[3];
        self::assertSame([], $fourth->agents);
        self::assertSame([], $fourth->placementSnapshot->records);
        self::assertGreaterThan($third->placementSnapshot->revision, $fourth->placementSnapshot->revision);
        $placement->tick(1000.4);
        self::assertCount(4, $this->views($mesh), 'An unchanged revision stays silent');

        $placement->onPeerHandshaked('newcomer');
        $handed = array_values(array_filter($mesh->sent, static fn(array $sent): bool => $sent[1] instanceof PeerPlacementViewDTO));
        self::assertCount(1, $handed);
        self::assertEquals($fourth, $handed[0][1], 'A quiet handshake carries the same complete empty version');
    }

    public function testUnfinishedRebuildPublishesNoPartialTruthEvenAfterItsLastReport(): void
    {
        $mesh = new FakePlacementMesh([], online: ['node-b']);
        $placement = new ClusterPlacement('leader', $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0, 7);
        $placement->tick(1000.0);
        $placement->onPeerHandshaked('newcomer');
        self::assertSame([], $this->views($mesh));
        self::assertNull($placement->placementSnapshot());
        self::assertSame([], array_filter($mesh->sent, static fn(array $sent): bool => $sent[1] instanceof PeerPlacementViewDTO));
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        self::assertNull($placement->placementSnapshot(), 'The final report is not the settlement barrier');
        $placement->tick(1000.1);
        self::assertSame([], $this->views($mesh)[0]->placementSnapshot->records);
    }

    public function testLosingLeadershipRetainsTheLatestOriginalBeforeClearingAndStopsRetries(): void
    {
        $mesh = new FakePlacementMesh([]);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        $placement->onBecameLeader(1000.0, 7);
        $placement->tick(1000.0);
        $waiting = new PlacementRecord('render', '9', null, PlacementState::Failed, 'last edit before loss');
        $placement->registry()->put($waiting);
        $placement->onLostLeadership();
        $retained = $placement->placementSnapshot();
        self::assertSame([$waiting], $retained?->records);
        self::assertSame(7, $retained?->term);
        self::assertSame([], $placement->registry()->all());
        $placement->tick(2000.0);
        self::assertSame([], $executor->executed);
        self::assertSame($retained, $placement->placementSnapshot());
        self::assertSame($retained, $placement->placementSnapshot(), 'Reads do not consume the retained copy');
        self::assertCount(1, $this->views($mesh), 'Followers publish no new versions');

        $mesh->online = ['node-b'];
        $placement->onBecameLeader(2000.0, 8);
        self::assertSame($retained, $placement->placementSnapshot(), 'A rebuilding leader keeps its previous complete version');
        $placement->onLostLeadership();
        self::assertSame($retained, $placement->placementSnapshot(), 'Losing an unfinished term preserves the complete copy');
    }

    /** @return iterable<string, array{PlacementState, bool}> Peer outcomes on followers and former leaders */
    public static function latePeerOutcomes(): iterable
    {
        foreach ([PlacementState::Started, PlacementState::Failed, PlacementState::Stopped] as $state) {
            yield $state->value . ' on follower' => [$state, false];
            yield $state->value . ' after loss' => [$state, true];
        }
    }

    /**
     * @param PlacementState $state Late status of the old instance
     * @param bool $led Whether this node previously owned the original
     */
    #[DataProvider('latePeerOutcomes')]
    public function testNonLeaderPeerOutcomesCannotWriteOriginalOrReplaceMirrorRouting(PlacementState $state, bool $led): void
    {
        $mesh = new FakePlacementMesh(['follower' => ['slots=10']]);
        $placement = new ClusterPlacement('follower', $mesh, new FakePlacementExecutor());
        $placement->onPlaceAgent('old', new PeerPlaceAgentDTO('render', 'actual', new PlacementId('old', 0, 1)));
        if ($led) {
            $placement->onBecameLeader(1000.0, 7);
            $placement->onLostLeadership();
        }
        $placement->onPlacementQuery('next', new PeerPlacementQueryDTO('query', 8));
        $view = new PeerPlacementViewDTO('next', ['fresh-host' => [new PeerPlacedAgentEntry('render', '9')]], new PlacementSnapshot(
            'next', 8, 1, [new PlacementRecord('render', '9', 'fresh-host', PlacementState::Started)],
        ));
        $placement->onPlacementView('next', $view);
        $mesh->sent = [];
        $revision = $placement->registry()->revision();

        FakePlacementMesh::status($placement, 'old', match ($state) {
            PlacementState::Started => PeerAgentStatusDTO::started('render', '9', 1),
            PlacementState::Failed => PeerAgentStatusDTO::failed('render', '9', 'late failure'),
            default => PeerAgentStatusDTO::stopped('render', '9'),
        });

        self::assertSame([], $placement->registry()->all());
        self::assertSame($revision, $placement->registry()->revision());
        self::assertSame([], $placement->placementRefusals());
        self::assertSame([], new ReflectionProperty(ClusterPlacement::class, 'lastPlacementRefusals')->getValue($placement));
        self::assertSame([], new ReflectionProperty(ClusterPlacement::class, 'retryAt')->getValue($placement));
        self::assertSame([], new ReflectionProperty(ClusterPlacement::class, 'retryTriggers')->getValue($placement));
        self::assertSame($view->placementSnapshot, $placement->placementSnapshot());
        self::assertSame('fresh-host', $placement->locate('render', '9')->nodeId);
        self::assertSame([], $mesh->sent);
        $placement->tick(2000.0);
        $placement->onPlacementQuery('next', new PeerPlacementQueryDTO('query', 8));
        self::assertEquals(
            [new PeerPlacedAgentEntry('render', 'actual', new PlacementId('old', 0, 1), PlacementState::Placing)],
            $mesh->sent[0][1]->agents,
        );
    }

    /** @return iterable<string, array{bool, bool}> Local completion and current-leader knowledge */
    public static function lateLocalOutcomes(): iterable
    {
        yield 'started with leader' => [false, true];
        yield 'failed with leader' => [true, true];
        yield 'started before leader known' => [false, false];
        yield 'failed before leader known' => [true, false];
    }

    /**
     * @param bool $failed Whether the local worker failed to start
     * @param bool $leaderKnown Whether a successor has already queried this node
     */
    #[DataProvider('lateLocalOutcomes')]
    public function testLocalDeferredOutcomeAfterLossKeepsHostedFactsSeparateFromOriginal(bool $failed, bool $leaderKnown): void
    {
        $mesh = new FakePlacementMesh(['follower' => ['slots=10']]);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('follower', $mesh, $executor);
        $placement->onBecameLeader(1000.0, 7);
        $placement->placeAgentOnNode('render', '9', 'follower');
        self::assertSame(PlacementState::Placing, $placement->registry()->get('render:9')?->state);
        $order = $placement->localPlacementId('render:9');
        $placement->onLostLeadership();
        $retained = $placement->placementSnapshot();
        if ($leaderKnown) {
            $placement->onPlacementQuery('next', new PeerPlacementQueryDTO('query', 8));
            $view = new PeerPlacementViewDTO('next', ['fresh-host' => [new PeerPlacedAgentEntry('render', '9')]], new PlacementSnapshot(
                'next', 8, 1, [new PlacementRecord('render', '9', 'fresh-host', PlacementState::Started)],
            ));
            $placement->onPlacementView('next', $view);
            $retained = $view->placementSnapshot;
        }
        $mesh->sent = [];
        $revision = $placement->registry()->revision();
        $executor->seatedWorkerId = -3;
        for ($repeat = 0; $repeat < 2; $repeat++) {
            if ($failed) {
                $placement->noteAgentStartFailed('render', '9', 'late local failure', placementId: $placement->localPlacementId('render:9'));
            } else {
                $placement->noteAgentStarted('render', '9', placementId: $placement->localPlacementId('render:9'));
            }
        }

        self::assertSame([], $placement->registry()->all());
        self::assertSame($revision, $placement->registry()->revision());
        self::assertSame([], $placement->placementRefusals());
        self::assertSame([], new ReflectionProperty(ClusterPlacement::class, 'lastPlacementRefusals')->getValue($placement));
        self::assertSame([], new ReflectionProperty(ClusterPlacement::class, 'retryAt')->getValue($placement));
        self::assertSame([], new ReflectionProperty(ClusterPlacement::class, 'retryTriggers')->getValue($placement));
        self::assertSame($retained, $placement->placementSnapshot());
        if ($leaderKnown) {
            self::assertSame('fresh-host', $placement->locate('render', '9')->nodeId);
            self::assertCount(1, $mesh->sent);
            self::assertSame('next', $mesh->sent[0][0]);
            self::assertEquals(
                $failed ? PeerAgentStatusDTO::failed('render', '9', 'late local failure', placementId: $order, authorityTerm: 8)
                    : PeerAgentStatusDTO::started('render', '9', -3, $order, 8, true),
                $mesh->sent[0][1],
            );
        } else {
            self::assertSame([], $mesh->sent);
        }
        $mesh->sent = [];
        $placement->tick(2000.0);
        self::assertCount(1, $executor->executed, 'No follower retry is armed');
        $placement->onPlacementQuery('next', new PeerPlacementQueryDTO('query', 8));
        $state = $leaderKnown ? PlacementState::Started : PlacementState::Placing;
        self::assertEquals(
            $failed ? [] : [new PeerPlacedAgentEntry('render', '9', $order, $state, $leaderKnown)],
            $mesh->sent[0][1]->agents,
        );
        self::assertSame($retained, $mesh->sent[0][1]->placementSnapshot);
        $mesh->sent = [];
        $placement->noteAgentStopped('render', '9');
        self::assertEquals($failed ? [] : [['next', PeerAgentStatusDTO::stopped('render', '9', $order, 8)]], $mesh->sent);
        $mesh->sent = [];
        $placement->onPlacementQuery('next', new PeerPlacementQueryDTO('query', 8));
        self::assertSame([], $mesh->sent[0][1]->agents);
    }

    public function testSlaveConfirmsEarlyViewAfterAQueryAndReportsHostedAgentsBesideItsMirror(): void
    {
        $mesh = new FakePlacementMesh(['follower' => ['slots=10']]);
        $placement = new ClusterPlacement('follower', $mesh, new FakePlacementExecutor());
        $view = $this->view('leader', 7, 5, [new PlacementRecord('render', '9', null, PlacementState::Unplaced, 'no room')]);
        $placement->onPlacementView('leader', $view);
        self::assertNull($placement->placementSnapshot(), 'The frame cannot name its own authority');
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('query', 7));
        $placement->tick(1000.0);
        self::assertSame($view->placementSnapshot, $placement->placementSnapshot(), 'The early view is not lost');
        self::assertSame([], $placement->registry()->all(), 'A mirror never writes the original');

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', 'actual', new PlacementId('leader', 7, 1)));
        $mesh->sent = [];
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('query', 7));
        $placement->onPeerHandshaked('other');
        self::assertCount(2, $mesh->sent);
        foreach ($mesh->sent as [, $report]) {
            self::assertInstanceOf(PeerPlacementReportDTO::class, $report);
            self::assertSame($view->placementSnapshot, $report->placementSnapshot);
            self::assertEquals(
                [new PeerPlacedAgentEntry('render', 'actual', $placement->localPlacementId('render:actual'), PlacementState::Placing)],
                $report->agents,
            );
        }
        $placement->onPlacementRelease('leader', 1000.0);
        self::assertNull($placement->knownLeaderId());
        self::assertSame($view->placementSnapshot, $placement->placementSnapshot(), 'No current leader does not erase history');
        $placement->tick(2000.0);
        $report = $mesh->broadcast[0];
        self::assertInstanceOf(PeerPlacementReportDTO::class, $report);
        self::assertSame([], $report->agents, 'Self-fence reports the emptied real hosted set');
        self::assertSame($view->placementSnapshot, $report->placementSnapshot, 'Self-fence retains the independent complete copy');
    }

    public function testMasterWaitsForConsensusToConfirmAnEarlyFrameWithoutASecondPublication(): void
    {
        $authority = $this->masterAuthority(null, 6);
        $placement = new ClusterPlacement('follower', new FakePlacementMesh([]), new FakePlacementExecutor());
        $view = $this->view('leader', 7, 5);
        $placement->onPlacementView('leader', $view);
        $latest = $this->view('leader', 7, 6);
        $placement->onPlacementView('leader', $latest);
        $placement->onPlacementView('leader', $this->view('leader', 7, 4));
        $placement->tick(1000.0);
        self::assertNull($placement->placementSnapshot());
        $authority->leader = 'leader';
        $authority->currentTerm = 7;
        $placement->tick(1000.1);
        self::assertSame($latest->placementSnapshot, $placement->placementSnapshot());
        self::assertSame([], $placement->registry()->all());
    }

    public function testAFutureCandidateDoesNotBlockTheCurrentConfirmedLeaderAndSurvivesUntilItsTerm(): void
    {
        $authority = $this->masterAuthority('leader', 7);
        $placement = new ClusterPlacement('follower', new FakePlacementMesh([]), new FakePlacementExecutor());
        $future = $this->view('next-leader', 8, 1);
        $placement->onPlacementView('next-leader', $future);
        self::assertNull($placement->placementSnapshot());
        $current = $this->view('leader', 7, 5);
        $placement->onPlacementView('leader', $current);
        self::assertSame($current->placementSnapshot, $placement->placementSnapshot());
        $authority->leader = 'next-leader';
        $authority->currentTerm = 8;
        $placement->tick(1000.0);
        self::assertSame($future->placementSnapshot, $placement->placementSnapshot());
    }

    public function testRecoveryReadsOnlyTheCurrentLeadersAcceptedCompleteView(): void
    {
        $authority = $this->masterAuthority('leader', 7);
        $placement = new ClusterPlacement('follower', new FakePlacementMesh([]), new FakePlacementExecutor());
        $recovery = new PlacementRecovery(new PlacementId('leader', 7, 1));
        $current = new PlacementRecord('render', '9', 'node-b', PlacementState::Started,
            placementId: new PlacementId('leader', 7, 2), workerStarted: true);
        $placement->onPlacementView('leader', $this->view('leader', 7, 1, [$current]));
        self::assertSame('node-b', $placement->startedPlacementRecovery('render:9', $recovery)?->nodeId);

        $next = new PlacementRecord('render', '9', 'node-c', PlacementState::Started,
            placementId: new PlacementId('next', 8, 1), workerStarted: true);
        $candidate = $this->view('next', 8, 1, [$next]);
        $placement->onPlacementView('next', $candidate);
        self::assertSame('node-b', $placement->startedPlacementRecovery('render:9', $recovery)?->nodeId);

        $authority->leader = 'next';
        $authority->currentTerm = 8;
        self::assertNull($placement->startedPlacementRecovery('render:9', $recovery));
        $placement->tick(1000.0);
        self::assertSame('node-c', $placement->startedPlacementRecovery('render:9', $recovery)?->nodeId);
    }

    public function testACompleteViewCannotRelayPastAnAlreadyKnownLaterLocalRetry(): void
    {
        $this->masterAuthority('leader', 7);
        $placement = new ClusterPlacement('follower', new FakePlacementMesh(['follower' => ['slots=10']]), new FakePlacementExecutor());
        $original = new PlacementId('leader', 7, 1);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', $original));
        $placement->beginLocalPlacementRecovery('render:9', $original);
        $recovery = $placement->placementRecovery('render:9');
        self::assertInstanceOf(PlacementRecovery::class, $recovery);
        $placement->noteAgentStartFailed('render', '9', 'replay failed', placementId: $original);

        $earlierRemote = new PlacementRecord('render', '9', 'node-b', PlacementState::Started,
            placementId: new PlacementId('leader', 7, 2), workerStarted: true);
        $placement->onPlacementView('leader', $this->view('leader', 7, 1, [$earlierRemote]));
        self::assertSame('node-b', $placement->startedPlacementRecovery('render:9', $recovery)?->nodeId);

        $localRetry = new PlacementId('leader', 7, 3);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', $localRetry));
        self::assertNull($placement->startedPlacementRecovery('render:9', $recovery));
        $placement->noteAgentStartFailed('render', '9', 'local retry failed', placementId: $localRetry);
        self::assertNull($placement->startedPlacementRecovery('render:9', $recovery));
        self::assertSame($original, $recovery->origin);

        $latest = new PlacementRecord('render', '9', 'node-c', PlacementState::Started,
            placementId: new PlacementId('leader', 7, 4), workerStarted: true);
        $placement->onPlacementView('leader', $this->view('leader', 7, 2, [$latest]));
        self::assertSame('node-c', $placement->startedPlacementRecovery('render:9', $recovery)?->nodeId);
    }

    public function testMasterRejectsWrongSourceAndStaleTermsAndNeverRollsBackRevisions(): void
    {
        $this->masterAuthority('leader', 8);
        $placement = new ClusterPlacement('follower', new FakePlacementMesh([]), new FakePlacementExecutor());
        $placement->onPlacementView('relay', $this->view('leader', 8, 99));
        $placement->onPlacementView('other', $this->view('other', 8, 99));
        self::assertNull($placement->placementSnapshot());
        $placement->tick(1000.0);
        $accepted = $this->view('leader', 8, 5);
        $placement->onPlacementView('leader', $accepted);
        self::assertSame($accepted->placementSnapshot, $placement->placementSnapshot());
        $placement->onPlacementView('leader', $this->view('leader', 7, 999));
        $placement->onPlacementView('leader', $this->view('leader', 8, 4));
        $placement->onPlacementView('leader', $this->view('leader', 8, 5));
        $placement->onPlacementView('other', $this->view('other', 8, 999));
        self::assertSame($accepted->placementSnapshot, $placement->placementSnapshot());
        $freshEmpty = $this->view('leader', 8, 6);
        $placement->onPlacementView('leader', $freshEmpty);
        self::assertSame($freshEmpty->placementSnapshot, $placement->placementSnapshot());
    }

    public function testSlaveRequiresItsKnownLeaderAndFencesTermsAcrossLeaderChanges(): void
    {
        $placement = new ClusterPlacement('follower', new FakePlacementMesh([]), new FakePlacementExecutor());
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('query', 7));
        $accepted = $this->view('leader', 7, 10);
        $placement->onPlacementView('leader', $accepted);
        $placement->onPlacementView('leader', $this->view('leader', 7, 9));
        $placement->onPlacementQuery('new-leader', new PeerPlacementQueryDTO('query', 8));
        $placement->onPlacementView('new-leader', $this->view('new-leader', 6, 999));
        $placement->onPlacementView('new-leader', $this->view('new-leader', 7, 999));
        self::assertSame($accepted->placementSnapshot, $placement->placementSnapshot());
        $newTerm = $this->view('new-leader', 8, 1);
        $placement->onPlacementView('new-leader', $newTerm);
        self::assertSame($newTerm->placementSnapshot, $placement->placementSnapshot(), 'Revision ordering is local to one term');
        $placement->onPlacementView('leader', $this->view('leader', 7, 9999));
        self::assertSame($newTerm->placementSnapshot, $placement->placementSnapshot());
    }

    public function testReportSnapshotRecordsAreNeverMistakenForTheSendersHostedSet(): void
    {
        $placement = new ClusterPlacement('leader', new FakePlacementMesh([], online: ['node-b']), new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0, 8);
        $snapshot = new PlacementSnapshot('old-leader', 7, 5, [
            new PlacementRecord('render', 'old', 'old-host', PlacementState::Started),
            new PlacementRecord('render', 'waiting', null, PlacementState::Failed, 'no room'),
        ]);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', 'actual')], $snapshot));
        self::assertCount(1, $placement->registry()->all());
        self::assertSame('node-b', $placement->registry()->get('render:actual')?->nodeId);
        self::assertNull($placement->registry()->get('render:old'));
        self::assertNull($placement->registry()->get('render:waiting'), 'Import waits for settlement');
        $placement->tick(1000.0);
        self::assertSame('node-b', $placement->registry()->get('render:actual')?->nodeId);
        self::assertSame(PlacementState::Unplaced, $placement->registry()->get('render:old')?->state);
        self::assertSame($snapshot->records[1], $placement->registry()->get('render:waiting'));
    }

    /** @return iterable<string, array{PlacementState, ?string}> Both waits, with nullable and historical hosts */
    public static function waitingRecords(): iterable
    {
        foreach ([PlacementState::Failed, PlacementState::Unplaced] as $state) {
            yield $state->value . ' without host' => [$state, null];
            yield $state->value . ' with history' => [$state, 'previous-host'];
        }
    }

    /**
     * @param PlacementState $state Waiting state to inherit
     * @param ?string $host Historical attempted node
     */
    #[DataProvider('waitingRecords')]
    public function testWaitingDiagnosisAndScheduleRecoverWithoutAnotherRequest(PlacementState $state, ?string $host): void
    {
        $mesh = new FakePlacementMesh(['leader' => []], online: ['leader', 'donor']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        $record = new PlacementRecord('render', 'subject', $host, $state, 'old diagnosis', [PlacementRefusal::noDeclaredCapacity('leader')]);
        $snapshot = new PlacementSnapshot('previous', 7, 99, [
            new PlacementRecord('render', 'first', null, PlacementState::Unplaced, 'first'),
            $record,
        ]);
        $placement->onBecameLeader(1000.0, 8);
        FakePlacementMesh::reply($placement, 'donor', new PeerPlacementReportDTO([], $snapshot));
        self::assertSame([], $placement->registry()->all());
        $placement->tick(1000.0);
        self::assertSame($record, $placement->registry()->get('render:subject'));
        $diagnosis = $placement->placementRefusals()[1];
        self::assertSame('old diagnosis', $diagnosis->reason);
        self::assertSame($record->refusals, $diagnosis->refusals);

        $mesh->capabilities['leader'] = ['slots=10'];
        $placement->registry()->put(new PlacementRecord('other', null, 'leader', PlacementState::Started));
        $placement->registry()->forget('other');
        $placement->noteNodeOnline('leader', 1001.0);
        $placement->tick(1001.0);
        if ($state === PlacementState::Failed) {
            self::assertSame([['render', 'first']], $executor->executed, 'Join and released capacity cannot shorten Failed pause');
            $placement->tick(1004.999);
            self::assertSame($record, $placement->registry()->get('render:subject'));
            $placement->tick(1005.0);
        }
        self::assertSame(PlacementState::Started, $placement->registry()->get('render:subject')?->state);
        $placement->tick(1010.0);
        self::assertSame(2, count($executor->executed), 'Each inherited intent starts once');
    }

    public function testLocalRetainedQueueSurvivesQuorumLossAndAnUnfinishedTerm(): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], online: ['leader']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        $placement->onBecameLeader(1000.0, 7);
        $record = new PlacementRecord('render', 'waiting', null, PlacementState::Failed, 'no worker');
        $placement->registry()->put($record);
        $placement->noteQuorumLost(1000.1);
        $retained = $placement->placementSnapshot();
        $mesh->online = ['missing'];
        $placement->onBecameLeader(1001.0, 8);
        $placement->tick(1010.0);
        self::assertSame([], $executor->executed);
        self::assertSame($retained, $placement->placementSnapshot());
        $newer = new PlacementSnapshot('leader', 7, 100, [$record]);
        FakePlacementMesh::reply($placement, 'donor', new PeerPlacementReportDTO([], $newer));
        $placement->onLostLeadership();
        self::assertSame($newer, $placement->placementSnapshot(), 'An unfinished rebuild retains its freshest complete donor');
        $mesh->online = ['leader'];
        $placement->onBecameLeader(1020.0, 9);
        self::assertSame($newer, $placement->placementSnapshot());
        $placement->tick(1020.0);
        self::assertSame($record, $placement->registry()->get('render:waiting'));
        $placement->tick(1024.999);
        self::assertSame([], $executor->executed);
        $placement->tick(1025.0);
        self::assertSame([['render', 'waiting']], $executor->executed);
    }

    public function testConfirmedHostsAreAdoptedWhileMissingIntentWaitsForTheFullGrace(): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], online: ['leader', 'donor']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor, failoverGraceMs: 8000);
        $placement->noteNodeOffline('absent', 1000.0);
        $placement->onBecameLeader(1001.0, 8);
        $snapshot = new PlacementSnapshot('previous', 7, 5, [
            new PlacementRecord('render', 'live', 'absent', PlacementState::Started),
            new PlacementRecord('render', 'placing', 'absent', PlacementState::Placing),
            new PlacementRecord('render', 'started', 'absent', PlacementState::Started),
            new PlacementRecord('render', 'refused', 'absent', PlacementState::Refused),
        ]);
        FakePlacementMesh::reply($placement, 'donor', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', 'live')], $snapshot));
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', 'live', 8, 'ask'));
        self::assertSame([], $mesh->sent, 'Even an old live record cannot answer before inheritance');
        self::assertNull($placement->placeAgentOnBestNode('render', 'live'));
        $placement->placeAgentOnNode('render', 'live', 'leader');
        $placement->tick(1007.999);
        self::assertSame([], $executor->executed);
        self::assertNull($placement->registry()->get('render:placing'));
        $placement->tick(1008.0);
        self::assertSame('donor', $placement->registry()->get('render:live')?->nodeId);
        self::assertSame([['render', 'placing']], $executor->executed, 'Only one inherited missing instance is retried per tick');
        self::assertSame(PlacementState::Unplaced, $placement->registry()->get('render:started')?->state);
        self::assertNull($placement->registry()->get('render:refused'), 'A new term re-evaluates Refused through ordinary declaration');
        $placement->tick(1008.0);
        self::assertSame([['render', 'placing'], ['render', 'started']], $executor->executed);
    }

    /** @return iterable<string, array{bool}> Both donor arrival orders */
    public static function donorOrders(): iterable
    {
        yield 'old first' => [false];
        yield 'new first' => [true];
    }

    /** @param bool $newFirst Whether the fresher complete copy arrives first */
    #[DataProvider('donorOrders')]
    public function testFreshEmptyCopyDeletesAllOlderWaitingAndCancellationIntent(bool $newFirst): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], online: ['first', 'last']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        $old = new PlacementSnapshot('previous', 7, 100, [
            new PlacementRecord('render', 'waiting', null, PlacementState::Unplaced, 'no room'),
            new PlacementRecord('render', 'canceled', null, PlacementState::Stopped),
        ]);
        $empty = new PlacementSnapshot('previous', 7, 101, []);
        $placement->onBecameLeader(1000.0, 8);
        FakePlacementMesh::reply($placement, 'first', new PeerPlacementReportDTO([], $newFirst ? $empty : $old));
        FakePlacementMesh::reply($placement, 'last', new PeerPlacementReportDTO(
            [new PeerPlacedAgentEntry('render', 'actual')], $newFirst ? $old : $empty,
        ));
        $placement->tick(1000.0);
        self::assertSame(
            ['render:actual'],
            array_map(static fn(PlacementRecord $record): string => $record->agentId(), $placement->registry()->all()),
        );
        self::assertSame([], $executor->executed, 'No union with older waiting intent');
        FakePlacementMesh::reply($placement, 'late', new PeerPlacementReportDTO([], $old));
        $placement->tick(1100.0);
        self::assertNull($placement->registry()->get('render:waiting'));
        self::assertNull($placement->registry()->get('render:canceled'));
    }

    /** @param bool $newFirst Whether the tombstone donor precedes the live report */
    #[DataProvider('donorOrders')]
    public function testInheritedCancellationSuppressesLiveReportsAndHoldsNewDemandUntilStopAck(bool $newFirst): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], linked: ['host'], online: ['leader', 'donor', 'host']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        $canceled = new PlacementSnapshot('previous', 7, 11, [new PlacementRecord('render', '9', 'host', PlacementState::Stopped)]);
        $old = new PlacementSnapshot('previous', 7, 10, [new PlacementRecord('render', '9', 'host', PlacementState::Started)]);
        $placement->onBecameLeader(1000.0, 8);
        if ($newFirst) {
            FakePlacementMesh::reply($placement, 'donor', new PeerPlacementReportDTO([], $canceled));
        }
        FakePlacementMesh::reply($placement, 'host', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')], $old));
        if (!$newFirst) {
            FakePlacementMesh::reply($placement, 'donor', new PeerPlacementReportDTO([], $canceled));
        }
        $placement->onPlacementRequest('new-asker', new PeerPlacementRequestDTO('render', '9', 8, 'ask'));
        self::assertSame([], $executor->executed);
        $placement->tick(1000.0);
        self::assertSame(PlacementState::Stopped, $placement->registry()->get('render:9')?->state);
        self::assertSame('host', $placement->registry()->get('render:9')?->nodeId);
        self::assertCount(1, array_filter($mesh->sent, static fn(array $sent): bool => $sent[1] instanceof PeerStopAgentDTO));
        self::assertSame([], $executor->executed);
        FakePlacementMesh::status($placement, 'other', PeerAgentStatusDTO::stopped('render', '9'));
        FakePlacementMesh::status($placement, 'host', PeerAgentStatusDTO::failed('render', '9', 'late failure'));
        FakePlacementMesh::status($placement, 'host', PeerAgentStatusDTO::started('render', '9', 1));
        self::assertSame([], $executor->executed);
        FakePlacementMesh::status($placement, 'host', PeerAgentStatusDTO::stopped('render', '9'));
        self::assertSame([['render', '9']], $executor->executed);
        FakePlacementMesh::status($placement, 'host', PeerAgentStatusDTO::stopped('render', '9'));
        self::assertSame('leader', $placement->registry()->get('render:9')?->nodeId);
        FakePlacementMesh::status($placement, 'leader', PeerAgentStatusDTO::stopped('render', '9'));
        self::assertNull($placement->registry()->get('render:9'), 'Normal idle stop still forgets the inherited replacement');
    }

    public function testConfirmedTombstoneStaysSuppressedWithoutAHostUntilExplicitDemand(): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], online: ['leader', 'donor']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        $tombstone = new PlacementRecord('render', '9', null, PlacementState::Stopped);
        $placement->onBecameLeader(1000.0, 8);
        FakePlacementMesh::reply($placement, 'donor', new PeerPlacementReportDTO([], new PlacementSnapshot('previous', 7, 11, [$tombstone])));
        $placement->tick(1000.0);
        $placement->tick(1100.0);
        self::assertEquals($tombstone, $placement->registry()->get('render:9'));
        self::assertSame([], $executor->executed);
        FakePlacementMesh::status($placement, 'host', PeerAgentStatusDTO::started('render', '9', 1));
        self::assertEquals($tombstone, $placement->registry()->get('render:9'));
        self::assertInstanceOf(PeerStopAgentDTO::class, $mesh->sent[0][1]);
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '9', 8, 'ask'));
        self::assertSame([['render', '9']], $executor->executed);
    }

    public function testDonorsOrderByTermThenRevisionAndLogSameTermLeaderConflicts(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'hil446-');
        Logger::setLogFile($log);
        try {
            $placement = new ClusterPlacement('leader', new FakePlacementMesh([], online: ['last']), new FakePlacementExecutor());
            $placement->onBecameLeader(1000.0, 9);
            FakePlacementMesh::reply($placement, 'donor', new PeerPlacementReportDTO([], new PlacementSnapshot('ancient', 6, 999, [])));
            $chosen = new PlacementSnapshot('previous', 7, 10, [new PlacementRecord('render', '9', null, PlacementState::Failed, 'chosen')]);
            FakePlacementMesh::reply($placement, 'relay', new PeerPlacementReportDTO([], $chosen));
            FakePlacementMesh::reply($placement, 'other', new PeerPlacementReportDTO([], new PlacementSnapshot('conflict', 7, 999, [])));
            FakePlacementMesh::reply($placement, 'other', new PeerPlacementReportDTO([], new PlacementSnapshot('previous', 7, 9, [])));
            FakePlacementMesh::reply($placement, 'last', new PeerPlacementReportDTO([], new PlacementSnapshot('future', 10, 999, [])));
            self::assertSame($chosen, $placement->placementSnapshot());
            $placement->tick(1000.0);
            self::assertSame($chosen->records, $placement->registry()->all());
            self::assertStringContainsString('Conflicting placement snapshot leaders', file_get_contents($log));
            self::assertStringContainsString('Placement queue recovered: 1 waiting agent(s)', file_get_contents($log));
            $placement->tick(1005.0);
            self::assertStringContainsString("Placement retry: 'render:9' trigger=new_leader", file_get_contents($log));
        } finally {
            Logger::resetLogFile();
            unlink($log);
        }
    }

    public function testOnlyTheLiveQueryCanAdoptAnOrderAndOnlyItsCurrentTermCanFailIt(): void
    {
        $mesh = new FakePlacementMesh(['host' => ['slots=10']], linked: ['host'], online: ['leader', 'host']);
        $placement = new ClusterPlacement('leader', $mesh, new FakePlacementExecutor());
        $id = new PlacementId('old-leader', 7, 11);
        $placement->onBecameLeader(1000.0, 8);
        $query = $mesh->broadcast[0];
        self::assertInstanceOf(PeerPlacementQueryDTO::class, $query);
        $pending = new PeerPlacedAgentEntry('render', '9', $id, PlacementState::Placing);
        $started = new PeerPlacedAgentEntry('render', '9', $id, PlacementState::Started, true);

        $placement->onPlacementRequest('caller', new PeerPlacementRequestDTO('render', '9', 8, 'ask'));
        $placement->onAgentStatus('host', PeerAgentStatusDTO::failed('render', '9', 'old refusal', placementId: $id, authorityTerm: 7));
        $placement->onPlacementReport('host', new PeerPlacementReportDTO([$started], queryId: 'old-query'));
        $placement->onPlacementReport('other', new PeerPlacementReportDTO([$started], queryId: $query->queryId));
        $placement->onPlacementReport('host', new PeerPlacementReportDTO([]));
        $placement->tick(1000.1);
        self::assertNull($placement->registry()->get('render:9'));
        self::assertSame([], $mesh->sent, 'Old reports cannot release held placement demand');

        $placement->onPlacementReport('host', new PeerPlacementReportDTO([$pending], queryId: $query->queryId));
        $placement->tick(1000.2);
        $record = $placement->registry()->get('render:9');
        self::assertSame(PlacementState::Placing, $record?->state);
        self::assertTrue($id->equals($record->placementId));
        self::assertFalse($record->workerStarted);
        self::assertSame([], $mesh->sent, 'The accepted pending order survives without a second Place');

        $placement->onAgentStatus('host', PeerAgentStatusDTO::started('render', '9', 3, $id, 8));
        self::assertFalse($placement->registry()->get('render:9')?->workerStarted);
        $placement->onAgentStatus('host', PeerAgentStatusDTO::started('render', '9', 3, $id, 7, true));
        self::assertFalse($placement->registry()->get('render:9')?->workerStarted);
        $placement->onAgentStatus('host', PeerAgentStatusDTO::started('render', '9', 3, $id, 8, true));
        self::assertTrue($placement->placementSnapshot()?->records[0]->workerStarted);

        $placement->onAgentStatus('host', PeerAgentStatusDTO::failed('render', '9', 'old refusal', placementId: $id, authorityTerm: 7));
        self::assertSame(PlacementState::Started, $placement->registry()->get('render:9')?->state);
        $placement->onAgentStatus('host', PeerAgentStatusDTO::failed('render', '9', 'current refusal', placementId: $id, authorityTerm: 8));
        self::assertSame(PlacementState::Failed, $placement->registry()->get('render:9')?->state);
        self::assertSame('current refusal', $placement->registry()->get('render:9')?->reason);
    }

    public function testSameTermRestartIssuesAboveAnOrderThatSurvivedOnAnotherNode(): void
    {
        $mesh = new FakePlacementMesh(['host' => ['slots=10']], linked: ['host'], online: ['host']);
        $placement = new ClusterPlacement('leader', $mesh, new FakePlacementExecutor());
        $surviving = new PlacementId('leader', 7, PHP_INT_MAX - 10);
        $placement->onBecameLeader(1000.0, 7);
        $query = $mesh->broadcast[0];
        self::assertInstanceOf(PeerPlacementQueryDTO::class, $query);
        $placement->onPlacementReport('host', new PeerPlacementReportDTO(
            [new PeerPlacedAgentEntry('render', '9', $surviving, PlacementState::Started, true)],
            queryId: $query->queryId,
        ));
        $placement->tick(1000.0);
        $live = $placement->placementSnapshot()?->records[0];
        self::assertTrue($surviving->equals($live?->placementId));
        self::assertTrue($live->workerStarted);
        $placement->placeAgentOnNode('render', '9', 'host');
        $next = $placement->registry()->get('render:9');
        self::assertGreaterThan($surviving->sequence, $next?->placementId?->sequence);
        self::assertFalse($next->workerStarted);
    }

    public function testUnplacedDonorKeepsTheFloorForAReplayHeldOnItsFormerHost(): void
    {
        $mesh = new FakePlacementMesh(['host' => ['slots=10']], linked: ['host'], online: ['host']);
        $leader = new ClusterPlacement('leader', $mesh, new FakePlacementExecutor());
        $leader->onBecameLeader(1000.0, 7);
        FakePlacementMesh::reply($leader, 'host', new PeerPlacementReportDTO([]));
        $leader->tick(1000.0);
        new ReflectionProperty(ClusterPlacement::class, 'placementSequence')->setValue($leader, PHP_INT_MAX - 20);
        $leader->placeAgentOnNode('render', '9', 'host');
        $order = $leader->registry()->get('render:9')->placementId;
        $recovery = new PlacementRecovery($order);
        $leader->onAgentStatus('host', PeerAgentStatusDTO::failed('render', '9', 'failed replay', placementId: $order, authorityTerm: 7));
        $mesh->online = [];
        $due = new ReflectionProperty(ClusterPlacement::class, 'retryAt')->getValue($leader)['render:9'];
        $leader->tick($due);
        $donor = $leader->placementSnapshot();
        self::assertSame(PlacementState::Unplaced, $donor?->records[0]->state);

        $newMesh = new FakePlacementMesh(['leader' => ['slots=10']], linked: ['host'], online: ['leader', 'host']);
        $restarted = new ClusterPlacement('leader', $newMesh, new FakePlacementExecutor());
        $restarted->onBecameLeader(1020.0, 7);
        FakePlacementMesh::reply($restarted, 'host', new PeerPlacementReportDTO([], $donor));
        $restarted->tick(1020.0);
        $provisional = $restarted->registry()->get('render:9');
        self::assertFalse($provisional->workerStarted);
        $restarted->noteAgentStarted('render', '9', placementId: $provisional->placementId);
        $restarted->tick(1020.1);
        $view = array_last($this->views($newMesh));
        $started = $view->placementSnapshot->records[0];
        self::assertTrue($started->workerStarted);
        self::assertTrue($recovery->precedesRemoteOrder($started->placementId), 'The real new start advances the order known to the source');

        $follower = new ClusterPlacement('host', new FakePlacementMesh([], linked: ['leader']), new FakePlacementExecutor());
        $follower->onPlacementQuery('leader', new PeerPlacementQueryDTO('rebuild', 7));
        $follower->onPlacementView('leader', $view);
        self::assertSame('leader', $follower->startedPlacementRecovery('render:9', $recovery)?->nodeId);
    }

    public function testRestartedSoleMasterPublishesAboveItsOldSameTermRevisionIncludingEmptyState(): void
    {
        $mesh = new FakePlacementMesh([], online: ['slave']);
        $placement = new ClusterPlacement('leader', $mesh, new FakePlacementExecutor());
        $old = new PlacementSnapshot('leader', 7, 900, []);
        $placement->onBecameLeader(1000.0, 7);
        FakePlacementMesh::reply($placement, 'slave', new PeerPlacementReportDTO([], $old));
        $placement->tick(1000.0);
        $first = $placement->placementSnapshot();
        self::assertGreaterThan(900, $first?->revision);
        self::assertSame([], $first?->records);
        self::assertEquals($first, $placement->placementSnapshot(), 'Inspect leaves the version unchanged');
        $placement->tick(1000.1);
        self::assertCount(1, $this->views($mesh), 'Unchanged ticks are silent above the revision floor too');
        $placement->stopAgentOnNode('render', '9', null);
        $placement->tick(1000.2);
        self::assertSame($first->revision + 1, $placement->placementSnapshot()?->revision);
        $placement->registry()->forget('render:9');
        $placement->tick(1000.3);
        self::assertSame($first->revision + 2, $placement->placementSnapshot()?->revision);
        $placement->onLostLeadership();
        $mesh->online = [];
        $placement->onBecameLeader(1001.0, 8);
        $placement->tick(1001.0);
        self::assertSame(8, $placement->placementSnapshot()?->term);
        self::assertLessThan(900, $placement->placementSnapshot()?->revision, 'Revision floors never replace term ordering');
    }

    public function testAnEmptyHostReportConfirmsInheritedCancellationBeforeReleasingHeldNamedDemand(): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], online: ['leader', 'host', 'donor']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        $snapshot = new PlacementSnapshot('previous', 7, 10, [new PlacementRecord('render', '9', 'host', PlacementState::Stopped)]);
        $placement->onBecameLeader(1000.0, 8);
        $placement->placeAgentOnNode('render', '9', 'leader');
        FakePlacementMesh::reply($placement, 'host', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, 'donor', new PeerPlacementReportDTO([], $snapshot));
        self::assertSame([], $executor->executed, 'The named door also waits after the final report');
        $placement->tick(1000.0);
        self::assertSame([['render', '9']], $executor->executed);
        self::assertSame(PlacementState::Started, $placement->registry()->get('render:9')?->state);
        self::assertSame('leader', $placement->registry()->get('render:9')?->nodeId);
    }

    public function testInheritedStopAckQueryPreservesTombstoneUntilANewRequest(): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], linked: ['host'], online: ['leader', 'host']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor, placementAckTimeoutMs: 500);
        $snapshot = new PlacementSnapshot('previous', 7, 10, [new PlacementRecord('render', '9', null, PlacementState::Stopped)]);
        $placement->onBecameLeader(1000.0, 8);
        FakePlacementMesh::reply($placement, 'host', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')], $snapshot));
        $placement->tick(1000.0);
        $placement->tick(1000.5);
        FakePlacementMesh::reply($placement, 'host', new PeerPlacementReportDTO([], $snapshot));
        self::assertSame(PlacementState::Stopped, $placement->registry()->get('render:9')?->state);
        self::assertNull($placement->registry()->get('render:9')?->nodeId);
        $placement->tick(1100.0);
        self::assertSame([], $executor->executed);
        $placement->onPlacementRequest('asker', new PeerPlacementRequestDTO('render', '9', 8, 'ask'));
        self::assertSame([['render', '9']], $executor->executed);
    }

    public function testInheritedCancellationRevokesTheNewLeadersOwnHostedInstance(): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], online: ['leader', 'donor']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        $order = new PlacementId('previous', 7, 1);
        $placement->onPlaceAgent('previous', new PeerPlaceAgentDTO('render', '9', $order));
        $snapshot = new PlacementSnapshot('previous', 7, 10, [
            new PlacementRecord('render', '9', 'leader', PlacementState::Stopped, placementId: $order),
        ]);
        $executor->holdStopped = true;
        $mesh->holdRtWritesAnswered = true;
        $placement->onBecameLeader(1000.0, 8);
        FakePlacementMesh::reply($placement, 'donor', new PeerPlacementReportDTO([], $snapshot));
        $placement->tick(1000.0);
        self::assertSame([['render', '9']], $executor->revoked);
        self::assertSame(PlacementState::Stopped, $placement->registry()->get('render:9')?->state);
        self::assertSame('leader', $placement->registry()->get('render:9')?->nodeId);
        $executor->releaseStopped();
        self::assertSame('leader', $placement->registry()->get('render:9')?->nodeId);
        $mesh->releaseRtWritesAnswered();
        self::assertNull($placement->registry()->get('render:9')?->nodeId);
        self::assertSame([], $placement->placementRefusals());
    }

    /**
     * @param PlacementState $terminal Prior authority's terminal decision
     * @param PlacementState $reported Host's accepted state under the new query
     * @param bool $sameOrder Whether the host still has the canceled assignment
     * @param bool $idlessDonor Whether the donor's terminal predates any assignment
     */
    #[DataProvider('terminalDonorOrders')]
    public function testTerminalDonorOnlyRevokesTheAssignmentItNames(
        PlacementState $terminal, PlacementState $reported, bool $sameOrder, bool $idlessDonor = false,
    ): void {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10'], 'host' => ['slots=10']], linked: ['host'], online: ['host']);
        $placement = new ClusterPlacement('leader', $mesh, new FakePlacementExecutor());
        $old = $idlessDonor ? null : new PlacementId('leader', 7, 1);
        $order = $sameOrder ? $old : new PlacementId('leader', 7, 2);
        $donor = new PlacementSnapshot('leader', 7, 10, [
            new PlacementRecord('render', '9', 'host', $terminal, placementId: $old),
        ]);
        $placement->onBecameLeader(1000.0, 7);
        FakePlacementMesh::reply($placement, 'host', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '9', $order, $reported, $reported === PlacementState::Started),
        ], $donor));
        $placement->tick(1000.0);
        $record = $placement->registry()->get('render:9');
        self::assertSame($order, $record->placementId);
        self::assertSame($sameOrder ? $terminal : $reported, $record->state);
        self::assertSame($sameOrder ? 1 : 0, count(array_filter(array_column($mesh->sent, 1),
            static fn(PeerDTO $frame): bool => $frame instanceof PeerStopAgentDTO)));
    }

    public static function terminalDonorOrders(): iterable
    {
        foreach ([PlacementState::Stopped, PlacementState::Refused] as $terminal) {
            foreach ([PlacementState::Placing, PlacementState::Started] as $reported) {
                yield "{$terminal->value} versus {$reported->value}, older order" => [$terminal, $reported, false];
                yield "{$terminal->value} versus {$reported->value}, same order" => [$terminal, $reported, true];
                yield "{$terminal->value} versus {$reported->value}, no donor order" => [$terminal, $reported, false, true];
            }
        }
        yield 'unmanaged cancellation' => [PlacementState::Stopped, PlacementState::Started, true, true];
    }

    public function testHeldNamedDemandCannotLiftANewTruthSourceRefusal(): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], online: ['leader', 'donor']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        $placement->onBecameLeader(1000.0, 8);
        $placement->placeAgentOnNode('render', '9', 'leader');
        $placement->refusePlacement('render', '9', 'leader');
        FakePlacementMesh::reply($placement, 'donor', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        self::assertSame([], $executor->executed);
        self::assertSame(PlacementState::Refused, $placement->registry()->get('render:9')?->state);
    }

    public function testSameTermRestartRecoversIntentAndRefusedWithoutTreatingEitherAsAHost(): void
    {
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], online: ['leader', 'slave']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        $snapshot = new PlacementSnapshot('leader', 7, 900, [
            new PlacementRecord('render', '9', null, PlacementState::Failed, 'old failure'),
            new PlacementRecord('render', 'refused', 'leader', PlacementState::Refused),
        ]);
        $placement->onBecameLeader(1000.0, 7);
        FakePlacementMesh::reply($placement, 'slave', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', 'refused')], $snapshot));
        $placement->tick(1000.0);
        self::assertSame([$snapshot->records[1], $snapshot->records[0]], $placement->registry()->all());
        self::assertGreaterThan(900, $placement->placementSnapshot()?->revision);
        [$nodeId, $stop] = $mesh->sent[0];
        self::assertSame('slave', $nodeId);
        self::assertInstanceOf(PeerStopAgentDTO::class, $stop);
        self::assertSame('render', $stop->agentType);
        self::assertSame('refused', $stop->agentIndex);
        self::assertSame(7, $stop->authorityTerm);
        $placement->tick(1005.0);
        self::assertSame([['render', '9']], $executor->executed, 'Refused never enters automatic retry, including after restart');
        $placement->onLostLeadership();
        $placement->onBecameLeader(1010.0, 8);
        FakePlacementMesh::reply($placement, 'slave', new PeerPlacementReportDTO([]));
        $placement->tick(1010.0);
        self::assertNull($placement->registry()->get('render:refused'), 'Only the new term releases the old refusal');
    }

    /**
     * @param FakePlacementMesh $mesh Recording transport
     * @return list<PeerPlacementViewDTO> Broadcast views
     */
    private function views(FakePlacementMesh $mesh): array
    {
        return array_values(array_filter($mesh->broadcast, static fn(PeerDTO $frame): bool => $frame instanceof PeerPlacementViewDTO));
    }

    /**
     * @param string $leader Leader that stamped the complete version
     * @param int $term Consensus term
     * @param int $revision Registry version
     * @param list<PlacementRecord> $records Full tracked intent
     * @return PeerPlacementViewDTO Complete version with an empty address projection
     */
    private function view(string $leader, int $term, int $revision, array $records = []): PeerPlacementViewDTO
    {
        return new PeerPlacementViewDTO($leader, [], new PlacementSnapshot($leader, $term, $revision, $records));
    }

    /**
     * @param ?string $leader Known consensus leader
     * @param int $term Known consensus term
     * @return SnapshotTestLeadership Mutable consensus knowledge
     */
    private function masterAuthority(?string $leader, int $term): SnapshotTestLeadership
    {
        $authority = new SnapshotTestLeadership($leader, $term);
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership($authority);

        return $authority;
    }
}

/** Master consensus knowledge advanced independently of arriving placement frames. */
final class SnapshotTestLeadership implements Leadership, ConsensusInspection
{
    /**
     * @param ?string $leader Known leader
     * @param int $currentTerm Known term
     */
    public function __construct(public ?string $leader, public int $currentTerm)
    {
    }

    /** @return bool This fixture follows */
    public function amLeader(): bool
    {
        return false;
    }

    /** @return ?string Current known leader */
    public function leaderId(): ?string
    {
        return $this->leader;
    }

    /** @return bool A known leader has a ready browser entrance */
    public function leaderWebSocketOpen(): bool
    {
        return $this->leader !== null;
    }

    /** @return bool A known leader implies a quorum in this fixture */
    public function hasQuorum(): bool
    {
        return $this->leader !== null;
    }

    /** @return int Current known term */
    public function term(): int
    {
        return $this->currentTerm;
    }

    /** @return ConsensusRole Follower role */
    public function consensusRole(): ConsensusRole
    {
        return ConsensusRole::Follower;
    }

    /** @return int Visible master count */
    public function onlineMasterCount(): int
    {
        return 3;
    }

    /** @return int Static master count */
    public function masterSetSize(): int
    {
        return 3;
    }

    /** @return int Majority size */
    public function quorumSize(): int
    {
        return 2;
    }
}

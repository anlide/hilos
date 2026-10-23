<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Cluster\NodeRole;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceOwner;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\StatusIncidentsActions;
use Hilos\Database\Actions\Collection\StatusIncidentUpdatesActions;
use Hilos\Database\Actions\Item\StatusIncidentActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use Hilos\Database\Entity\Item\StatusIncidentUpdate as EntityStatusIncidentUpdate;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Object\Collection\StatusIncidents as ObjectStatusIncidents;
use Hilos\Database\Object\Collection\StatusIncidentUpdates as ObjectStatusIncidentUpdates;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Collection\StatusIncidents;
use Hilos\Database\View\Collection\StatusIncidentUpdates;
use Hilos\Hilos;
use Hilos\Notification\HilosNotifier;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\DaemonAgentPicture;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Status\Exception\StatusIncidentWriteException;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusComponentState;
use Hilos\Status\StatusIncidentOutcome;
use Hilos\Status\StatusSeverity;
use Hilos\Status\StatusSettingsCatalog;
use Hilos\Status\StatusSnapshot;
use Hilos\Status\StatusUpdateKind;
use Hilos\Status\StatusUpdateText;
use Hilos\Testing\IntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/** Real ORM storage and the bounded automatic machine driven through the collector observation seam. */
#[IntegrationTest]
final class StatusMachineIntegrationTest extends StatusStorageIntegrationTestCase
{
    /** Partial frames and unchanged whole baselines cannot create a candidate. Deadlines need no new frame. */
    public function testFreshAbsenceCreatesOneCandidateAndTimerPublishesTheSameFact(): void
    {
        $owner = $this->startMachine();
        $this->report($owner, false, 1000, [false, false]);
        $owner->tickAt(1000);
        $this->report($owner, true, 1100, [false, false]);
        $owner->tickAt(1100);
        $this->report($owner, true, 1100, [false, false]);
        $owner->tickAt(1200);
        self::assertSame([], $this->storedIncidents());
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);

        $this->report($owner, false, 1300, [false, false]);
        $owner->tickAt(2000);
        $id = $this->storedIncidents()[0]['id'];
        self::assertSame('2000', $this->storedIncidents()[0]['detected_at'], 'Detection uses the local tick clock');
        self::assertSame('302000', $this->storedIncidents()[0]['publish_at']);
        self::assertSame('major_outage', $this->storedIncidents()[0]['severity']);
        self::assertSame(StatusComponentState::CHECKING, StatusSnapshot::read()->components['service']->state);
        self::assertSame(0, Hilos::$rt->hilosStatusComponents['service']->liveInstances);
        self::assertSame(1300, Hilos::$rt->hilosStatusComponents['service']->observedAt);
        $owner->tickAt(301999);
        self::assertNull($this->storedIncidents()[0]['published_at']);
        $owner->tickAt(302000);
        $owner->tickAt(400000);
        self::assertCount(1, $this->storedIncidents());
        self::assertSame($id, $this->storedIncidents()[0]['id']);
        self::assertSame('302000', $this->storedIncidents()[0]['published_at']);
        self::assertSame([['kind' => 'published', 'severity' => 'major_outage', 'text' => 'Major outage.', 'at' => '302000']],
            $this->storedUpdates());
        self::assertSame(StatusComponentState::MAJOR_OUTAGE, StatusSnapshot::read()->components['service']->state);
    }

    /** A hidden Major peak does not leak into publication; only later published severity raises the peak. */
    public function testContinuousSeverityChangesAndPublishedRecoveryHaveExactlyOneTimelineRowEach(): void
    {
        $owner = $this->startMachine();
        $this->freshReport($owner, 1000, [false, false]);
        $owner->tickAt(1000);
        $id = $this->storedIncidents()[0]['id'];
        $this->report($owner, false, 2000, [true, false]);
        $owner->tickAt(2000);
        self::assertSame('partial_outage', $this->storedIncidents()[0]['severity']);
        self::assertNull($this->storedIncidents()[0]['peak_severity']);
        self::assertSame([], $this->storedUpdates());
        $owner->tickAt(301000);
        self::assertSame('partial_outage', $this->storedIncidents()[0]['peak_severity']);
        $this->report($owner, false, 302000, [false, false]);
        $owner->tickAt(302000);
        $owner->tickAt(302001);
        $this->report($owner, false, 303000, [true, false]);
        $owner->tickAt(303000);
        self::assertSame($id, $this->storedIncidents()[0]['id']);
        self::assertSame('1000', $this->storedIncidents()[0]['detected_at']);
        self::assertSame('301000', $this->storedIncidents()[0]['publish_at']);
        self::assertSame('major_outage', $this->storedIncidents()[0]['peak_severity']);
        self::assertSame('partial_outage', $this->storedIncidents()[0]['severity']);
        $this->report($owner, false, 304000, [true, true]);
        $owner->tickAt(304000);
        $owner->tickAt(305000);
        self::assertSame('resolved', $this->storedIncidents()[0]['outcome']);
        self::assertNull($this->storedIncidents()[0]['active_component_key']);
        self::assertSame([
            ['kind' => 'published', 'severity' => 'partial_outage', 'text' => 'Partial outage.', 'at' => '301000'],
            ['kind' => 'severity_changed', 'severity' => 'major_outage', 'text' => 'Major outage.', 'at' => '302000'],
            ['kind' => 'severity_changed', 'severity' => 'partial_outage', 'text' => 'Partial outage.', 'at' => '303000'],
            ['kind' => 'resolved', 'severity' => 'partial_outage', 'text' => 'Resolved.', 'at' => '304000'],
        ], $this->storedUpdates());
        self::assertSame(StatusComponentState::OPERATIONAL, StatusSnapshot::read()->components['service']->state);
        self::assertSame([], StatusSnapshot::read()->incidents);
        $this->report($owner, false, 306000, [false, false]);
        $owner->tickAt(306000);
        self::assertCount(2, $this->storedIncidents());
        self::assertNotSame($id, $this->storedIncidents()[1]['id']);
        self::assertSame('306000', $this->storedIncidents()[1]['detected_at']);
    }

    /** Full recovery before publication ends the candidate without leaving a public timeline. */
    public function testShortFactAndNewSettingOnlyAffectTheNextCandidate(): void
    {
        $owner = $this->startMachine();
        $this->freshReport($owner, 1000, [true, false]);
        $owner->tickAt(1000);
        $this->delay(0);
        $owner->tickAt(2000);
        self::assertSame('301000', $this->storedIncidents()[0]['publish_at']);
        self::assertNull($this->storedIncidents()[0]['published_at']);
        $this->report($owner, false, 3000, [true, true]);
        $owner->tickAt(3000);
        self::assertSame('recovered_before_publication', $this->storedIncidents()[0]['outcome']);
        self::assertSame([], $this->storedUpdates());
        $this->report($owner, false, 4000, [true, false]);
        $owner->tickAt(4000);
        $owner->tickAt(4001);
        self::assertSame('4000', $this->storedIncidents()[1]['detected_at']);
        self::assertSame('4000', $this->storedIncidents()[1]['publish_at']);
        self::assertSame('4000', $this->storedIncidents()[1]['published_at']);
        self::assertSame([['kind' => 'published', 'severity' => 'partial_outage', 'text' => 'Partial outage.', 'at' => '4000']],
            $this->storedUpdates());
    }

    /** @return iterable<string, array{int}> Suppression may precede or follow an unprocessed deadline */
    public static function suppressionMoments(): iterable
    {
        yield 'before deadline' => [1500];
        yield 'after deadline' => [400000];
    }

    /** @param int $suppressedAt Suppression that lasts through severity changes and a new owner instance */
    #[DataProvider('suppressionMoments')]
    public function testSuppressionAndDeadlineSurviveRestartUntilFullRecovery(int $suppressedAt): void
    {
        $owner = $this->startMachine();
        $this->freshReport($owner, 1000, [true, false]);
        $owner->tickAt(1000);
        $id = (int)$this->storedIncidents()[0]['id'];
        Hilos::$db->statusIncidents[$id]->actions->suppress($suppressedAt);
        $this->delay(0);
        $owner->tickAt(max(301001, $suppressedAt));
        $owner->onStop();
        $owner = $this->restartMachine();
        $owner->tickAt(400000);
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);
        $this->report($owner, true, 400001, [false, false]);
        $owner->tickAt(400001);
        self::assertNull($this->storedIncidents()[0]['published_at']);
        $this->report($owner, false, 400002, [false, false]);
        $owner->tickAt(400002);
        self::assertSame('major_outage', $this->storedIncidents()[0]['severity']);
        self::assertSame((string)$suppressedAt, $this->storedIncidents()[0]['suppressed_at']);
        self::assertSame('301000', $this->storedIncidents()[0]['publish_at']);
        self::assertSame(StatusComponentState::CHECKING, StatusSnapshot::read()->components['service']->state);
        $this->report($owner, false, 400003, [true, true]);
        $owner->tickAt(400003);
        self::assertSame('canceled_publication', $this->storedIncidents()[0]['outcome']);
        self::assertSame([], $this->storedUpdates());
        self::assertNull($this->storedIncidents()[0]['active_component_key']);
    }

    /** Restart never promotes or resolves from a baseline; the existing deadline is already late on fresh absence. */
    public function testLateDeadlineAfterRestartWaitsForANewUsableObservation(): void
    {
        $owner = $this->startMachine();
        $this->freshReport($owner, 1000, [false, false]);
        $owner->tickAt(1000);
        $id = $this->storedIncidents()[0]['id'];
        $owner = $this->restartMachine();
        $this->report($owner, true, 400000, [true, true]);
        $owner->tickAt(400000);
        self::assertNull($this->storedIncidents()[0]['resolved_at']);
        self::assertNull($this->storedIncidents()[0]['published_at']);
        $this->report($owner, false, 400001, [false, false]);
        $owner->tickAt(400001);
        self::assertSame($id, $this->storedIncidents()[0]['id']);
        self::assertSame('301000', $this->storedIncidents()[0]['publish_at']);
        self::assertSame('400001', $this->storedIncidents()[0]['published_at']);
        self::assertCount(1, $this->storedUpdates());
    }

    /** @return iterable<string, array{string}> Every active phase projects Maintenance */
    public static function machineFreezePhases(): iterable
    {
        yield 'activating' => [ProtectedModeRuntime::PHASE_ACTIVATING];
        yield 'active' => [ProtectedModeRuntime::PHASE_ACTIVE];
        yield 'verifying' => [ProtectedModeRuntime::PHASE_VERIFYING];
        yield 'deactivating' => [ProtectedModeRuntime::PHASE_DEACTIVATING];
    }

    /** @param string $phase Phase that must never infer an outage from a stopped roster */
    #[DataProvider('machineFreezePhases')]
    public function testFreezePreservesDurableFactsAndLiftRequiresFreshness(string $phase): void
    {
        $owner = $this->startMachine();
        $this->freshReport($owner, 1000, [false, false]);
        $owner->tickAt(1000);
        $before = $this->storedIncidents();
        $this->machineRuntime->phase($phase);
        $owner->tickAt(400000);
        $this->report($owner, true, 400001, [true, true]);
        self::assertSame($before, $this->storedIncidents());
        self::assertSame(StatusComponentState::MAINTENANCE, StatusSnapshot::read()->components['service']->state);
        if (in_array($phase, [ProtectedModeRuntime::PHASE_ACTIVATING, ProtectedModeRuntime::PHASE_ACTIVE], true)) {
            self::assertNull(StatusSnapshot::read()->incidents);
            // Remove the facade entirely: any DB access in a silenced tick/projection would fail.
            $db = Hilos::$db;
            Hilos::$db = null;
            $this->drainMachineSignals();
            try {
                $owner->tickAt(400002);
                $owner->onStop();
                self::assertSame(StatusComponentState::MAINTENANCE, StatusSnapshot::read()->components['service']->state);
                self::assertSame([], $this->drainMachineSignals());
            } finally {
                Hilos::$db = $db;
            }
        }
        $this->machineRuntime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $owner->tickAt(500000);
        $this->report($owner, true, 500001, [true, true]);
        $owner->tickAt(500001);
        self::assertSame($before, $this->storedIncidents());
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);
        $this->report($owner, false, 500002, [true, true]);
        $owner->tickAt(500002);
        self::assertSame('recovered_before_publication', $this->storedIncidents()[0]['outcome']);
        self::assertSame('301000', $this->storedIncidents()[0]['publish_at']);
        self::assertSame([], $this->storedUpdates());
    }

    /** An unusable live report neither publishes a candidate nor resolves a published incident. */
    public function testUnknownObservationCannotPublishOrResolve(): void
    {
        $owner = $this->startMachine();
        $this->freshReport($owner, 1000, [false, false]);
        $owner->tickAt(1000);
        $this->report($owner, false, 2000, [null, true]);
        $owner->tickAt(400000);
        self::assertNull($this->storedIncidents()[0]['published_at']);
        self::assertNull($this->storedIncidents()[0]['resolved_at']);
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);
        $this->report($owner, false, 400001, [false, false]);
        $owner->tickAt(400001);
        $this->report($owner, false, 400002, [true, null]);
        $owner->tickAt(500000);
        self::assertSame('400001', $this->storedIncidents()[0]['published_at']);
        self::assertNull($this->storedIncidents()[0]['resolved_at']);
        self::assertCount(1, $this->storedUpdates());
    }

    /** Invalid durations fail before candidate creation, and the component's RT edit rolls back too. */
    public function testNegativeAndOverflowDelayNeverLeaveHalfState(): void
    {
        $owner = $this->startMachine();
        $this->freshReport($owner, 1000, [false, false]);
        foreach ([-1, PHP_INT_MAX, intdiv(PHP_INT_MAX - 1000, 1000) + 1] as $seconds) {
            $this->delay($seconds, validate: false);
            try {
                $owner->tickAt(1000);
                self::fail('An invalid deadline must refuse');
            } catch (SettingException | StatusIncidentWriteException) {
                self::assertSame([], $this->storedIncidents());
                self::assertSame([], $this->storedUpdates());
                self::assertCount(0, Hilos::$rt->hilosStatusComponents);
            }
        }
        $this->delay(0);
        $owner->tickAt(1000);
        self::assertSame('1000', $this->storedIncidents()[0]['publish_at']);
        self::assertCount(1, $this->storedUpdates());
    }

    /** Failure after timeline insertion restores the original incident, counts and announcements; the cursor retries it. */
    public function testFailedTransitionRollsBackDbCacheRuntimeAndRetainsTheFailedComponent(): void
    {
        $owner = $this->startMachine();
        $this->freshReport($owner, 1000, [true, false]);
        $owner->tickAt(1000);
        $before = $this->storedIncidents();
        $beforeCounts = Hilos::$rt->hilosStatusComponents['service']->toArray();
        $this->report($owner, false, 301000, [false, false]);
        $this->drainMachineSignals();
        $failure = new StatusMachineFailingMirror();
        SourceChangeBus::subscribe($failure);
        try {
            $owner->tickAt(301000);
            self::fail('The injected timeline failure must reach the caller');
        } catch (SourceChangeSubscriberException) {
            self::assertFalse($failure->armed, 'Failure happened after the real timeline insert');
            self::assertSame($before, $this->storedIncidents());
            self::assertSame([], $this->storedUpdates());
            self::assertNull(Hilos::$db->statusIncidents[(int)$before[0]['id']]->publishedAt);
            self::assertSame('partial_outage', Hilos::$db->statusIncidents[(int)$before[0]['id']]->severity);
            self::assertSame($beforeCounts, Hilos::$rt->hilosStatusComponents['service']->toArray());
            self::assertSame([], $this->drainMachineSignals());
        }
        $owner->tickAt(301001);
        self::assertSame($before[0]['id'], $this->storedIncidents()[0]['id']);
        self::assertSame('301001', $this->storedIncidents()[0]['published_at']);
        self::assertSame('major_outage', $this->storedIncidents()[0]['severity']);
        self::assertSame(0, Hilos::$rt->hilosStatusComponents['service']->liveInstances);
        self::assertCount(1, $this->storedUpdates());
    }

    /** A zero-delay failure rolls back creation as well as promotion, timeline and the new RT row. */
    public function testFailedImmediatePublicationLeavesNoCandidateOrCounts(): void
    {
        $owner = $this->startMachine();
        $this->delay(0);
        $this->freshReport($owner, 1000, [false, false]);
        SourceChangeBus::subscribe(new StatusMachineFailingMirror());
        try {
            $owner->tickAt(1000);
            self::fail('The injected timeline failure must reach the caller');
        } catch (SourceChangeSubscriberException) {
            self::assertSame([], $this->storedIncidents());
            self::assertSame([], $this->storedUpdates());
            self::assertCount(0, Hilos::$rt->hilosStatusComponents);
        }
        $owner->tickAt(1001);
        self::assertCount(1, $this->storedIncidents());
        self::assertSame('1001', $this->storedIncidents()[0]['published_at']);
        self::assertCount(1, $this->storedUpdates());
    }

    /** A settled cursor sleeps until a changed fact or deadline; lease renewal has no database polling. */
    public function testIdleTicksDoNotQueryCurrentIncidentsAndMissingSourcesNeverAttestHealth(): void
    {
        $owner = $this->startMachine();
        $this->freshReport($owner, 1000, [true, true]);
        $owner->tickAt(1000);
        $owner->tickAt(1001); // Settle a picture accepted before the first cursor portion.
        $queries = Hilos::$db->statusIncidents->selections;
        $owner->tickAt(2000);
        $owner->tickAt(200000);
        self::assertSame($queries, Hilos::$db->statusIncidents->selections);
        self::assertSame(StatusComponentState::OPERATIONAL, StatusSnapshot::read()->components['service']->state);
        $db = Hilos::$db;
        Hilos::$db = null;
        try {
            self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);
            self::assertNull(StatusSnapshot::read()->incidents);
        } finally {
            Hilos::$db = $db;
        }
        Hilos::$rt = null;
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);
    }

    /** A large catalog reads exactly its current portion and eventually checks every deadline without new reports. */
    public function testReconciliationReadsAndTransitionsAtMost32ComponentsPerTick(): void
    {
        $owner = $this->startMachine(StatusManyComponentsHilos::class);
        $this->freshReport($owner, 1000, [false, false]);
        $owner->tickAt(1000);
        self::assertCount(32, $this->storedIncidents());
        self::assertCount(32, Hilos::$rt->hilosStatusComponents);
        $owner->tickAt(1001);
        self::assertCount(35, $this->storedIncidents());
        self::assertCount(35, Hilos::$rt->hilosStatusComponents);
        $owner->tickAt(400000);
        self::assertCount(32, $this->storedUpdates());
        $owner->tickAt(400001);
        self::assertCount(35, $this->storedUpdates());
        self::assertSame([
            [EntityStatusIncident::active_component_key, ...array_map(static fn (int $n): string => 'c' . $n, range(1, 32))],
            [EntityStatusIncident::active_component_key, 'c33', 'c34', 'c35'],
            [EntityStatusIncident::active_component_key, ...array_map(static fn (int $n): string => 'c' . $n, range(1, 32))],
            [EntityStatusIncident::active_component_key, 'c33', 'c34', 'c35'],
        ], Hilos::$db->statusIncidents->selections);
    }

    /** Feature activation uses the same storage writes through the production conditional mounts. */
    public function testProductionFeatureMountWritesBothChains(): void
    {
        $previousApp = Hilos::appClass();
        $previousDb = Hilos::$db;
        $binding = new ReflectionProperty(Hilos::class, 'appClass');
        try {
            $binding->setValue(null, StatusProductionMountHilos::class);
            Hilos::$db = new StatusProductionDbContext();
            Hilos::$db->configure();
            $candidate = Hilos::$db->statusIncidents->actions->candidate('service', 'Service', StatusSeverity::MAJOR_OUTAGE, 1000, 2000);
            Hilos::$db->statusIncidents[$candidate->id]->actions->publish(2000);
            Hilos::$db->statusIncidentUpdates->actions->append(
                $candidate->id, StatusUpdateKind::PUBLISHED, StatusSeverity::MAJOR_OUTAGE, new StatusUpdateText('Service outage'), 2000,
            );
            self::assertSame('2000', Database::sql(
                'SELECT published_at FROM hilos_status_incident WHERE id = ?', [$candidate->id],
            )->rows()[0]['published_at']);
            self::assertSame('1', Database::sql(
                'SELECT COUNT(*) AS n FROM hilos_status_incident_update WHERE incident_id = ?', [$candidate->id],
            )->rows()[0]['n']);
        } finally {
            Hilos::$db = $previousDb;
            $binding->setValue(null, $previousApp);
        }
    }

    /** A candidate's deadline, public name snapshot and active key are durable; it has no public peak. */
    public function testCandidateAndIndexedCurrentSelection(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::PARTIAL_OUTAGE, 1000, 301000);
        Hilos::$db->statusIncidents->actions->candidate('service', 'Service', StatusSeverity::MAJOR_OUTAGE, 2000, 302000);

        self::assertSame([
            'component_key' => 'mail', 'component_name' => 'Mail', 'active_component_key' => 'mail',
            'severity' => 'partial_outage', 'peak_severity' => null, 'detected_at' => '1000', 'publish_at' => '301000',
            'published_at' => null, 'suppressed_at' => null, 'resolved_at' => null, 'outcome' => null,
        ], Database::sql(
            'SELECT component_key, component_name, active_component_key, severity, peak_severity, detected_at, publish_at, '
            . 'published_at, suppressed_at, resolved_at, outcome FROM hilos_status_incident WHERE id = ?', [$candidate->id],
        )->rows()[0]);
        self::assertSame([$candidate->id], array_values(array_map(
            static fn ($row): ?int => $row->id,
            iterator_to_array(Hilos::$db->statusIncidents->whereColumnIn(EntityStatusIncident::active_component_key, 'mail')),
        )));
        self::assertCount(0, Hilos::$db->statusIncidents[$candidate->id]->updates);
        self::assertSame('mail', $candidate->toArray()['componentKey']);
        self::assertArrayNotHasKey('component_key', $candidate->toArray());
    }

    /** The SQL unique key, rather than only a cached lookup, refuses a second open fact. */
    public function testOnlyOneOpenIncidentPerComponent(): void
    {
        Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::PARTIAL_OUTAGE, 1000, 2000);
        $this->expectException(DatabaseException::class);
        Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::MAJOR_OUTAGE, 1100, 2100);
    }

    /** Hidden changes preserve the deadline and do not raise the later public peak. */
    public function testItemTransitionsAndPublishedPeak(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::MAJOR_OUTAGE, 1000, 3000);
        $id = $candidate->id;
        Hilos::$db->statusIncidents[$id]->actions->changeSeverity(StatusSeverity::PARTIAL_OUTAGE);
        self::assertNull(Hilos::$db->statusIncidents[$id]->peakSeverity);
        Hilos::$db->statusIncidents[$id]->actions->publish(2000);
        Hilos::$db->statusIncidents[$id]->actions->publish(2500);
        self::assertSame(2000, Hilos::$db->statusIncidents[$id]->publishedAt);
        self::assertSame(StatusSeverity::PARTIAL_OUTAGE->value, Hilos::$db->statusIncidents[$id]->peakSeverity);
        Hilos::$db->statusIncidents[$id]->actions->changeSeverity(StatusSeverity::MAJOR_OUTAGE);
        Hilos::$db->statusIncidents[$id]->actions->changeSeverity(StatusSeverity::PARTIAL_OUTAGE);
        Hilos::$db->statusIncidents[$id]->actions->resolve(4000);

        self::assertSame([
            'active_component_key' => null, 'severity' => 'partial_outage', 'peak_severity' => 'major_outage',
            'detected_at' => '1000', 'publish_at' => '3000', 'published_at' => '2000', 'resolved_at' => '4000', 'outcome' => 'resolved',
        ], Database::sql(
            'SELECT active_component_key, severity, peak_severity, detected_at, publish_at, published_at, resolved_at, outcome '
            . 'FROM hilos_status_incident WHERE id = ?', [$id],
        )->rows()[0]);
        $next = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail renamed', StatusSeverity::MAJOR_OUTAGE, 5000, 6000);
        self::assertNotSame($id, $next->id);
        self::assertSame('Mail', Hilos::$db->statusIncidents[$id]->componentName);
    }

    /** Suppression survives severity changes and cannot be undone through publication. */
    public function testSuppressedCandidateEndsWithoutPublication(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::PARTIAL_OUTAGE, 1000, 2000);
        $id = $candidate->id;
        Hilos::$db->statusIncidents[$id]->actions->suppress(1500);
        Hilos::$db->statusIncidents[$id]->actions->suppress(1700);
        Hilos::$db->statusIncidents[$id]->actions->changeSeverity(StatusSeverity::MAJOR_OUTAGE);
        try {
            Hilos::$db->statusIncidents[$id]->actions->publish(1900);
            self::fail('Suppressed publication must refuse');
        } catch (StatusIncidentWriteException) {
            self::assertNull(Hilos::$db->statusIncidents[$id]->publishedAt);
        }
        Hilos::$db->statusIncidents[$id]->actions->resolve(2000);
        self::assertSame(StatusIncidentOutcome::CANCELED_PUBLICATION->value, Hilos::$db->statusIncidents[$id]->outcome);
        self::assertSame(1500, Hilos::$db->statusIncidents[$id]->suppressedAt);
        self::assertNull(Hilos::$db->statusIncidents[$id]->peakSeverity);
        self::assertCount(0, Hilos::$db->statusIncidents[$id]->updates);
        self::assertSame('canceled_publication', Database::sql(
            'SELECT outcome FROM hilos_status_incident WHERE id = ?', [$id],
        )->rows()[0]['outcome']);
    }

    /** A recovered candidate is historical, releases its active key and rejects further edits. */
    public function testUnpublishedRecoveryAndEndedRefusal(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::MAJOR_OUTAGE, 1000, 2000);
        $id = $candidate->id;
        Hilos::$db->statusIncidents[$id]->actions->resolve(1200);
        Hilos::$db->statusIncidents[$id]->actions->resolve(1500);
        self::assertSame(1200, Hilos::$db->statusIncidents[$id]->resolvedAt);
        self::assertSame(StatusIncidentOutcome::RECOVERED_BEFORE_PUBLICATION->value, Hilos::$db->statusIncidents[$id]->outcome);
        self::assertNull(Hilos::$db->statusIncidents[$id]->activeComponentKey);
        $this->expectException(StatusIncidentWriteException::class);
        Hilos::$db->statusIncidents[$id]->actions->changeSeverity(StatusSeverity::PARTIAL_OUTAGE);
    }

    /** The immutable FK bridge and composite request lookup preserve request identity and text. */
    public function testTimelineReplayAndDirectRelations(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::MAJOR_OUTAGE, 1000, 2000);
        $id = $candidate->id;
        Hilos::$db->statusIncidents[$id]->actions->publish(2000);
        $automatic = Hilos::$db->statusIncidentUpdates->actions->append(
            $id, StatusUpdateKind::PUBLISHED, StatusSeverity::MAJOR_OUTAGE, new StatusUpdateText('Detected automatically.'), 2000,
        );
        $team = Hilos::$db->statusIncidentUpdates->actions->append(
            $id, StatusUpdateKind::TEAM, StatusSeverity::MAJOR_OUTAGE, new StatusUpdateText('  Investigating.  '), 2100, 'request-A',
        );
        $teamId = $team->id;
        self::assertNull($automatic->requestId);
        self::assertSame($teamId, Hilos::$db->statusIncidentUpdates->forRequest($id, 'request-A')?->id);
        self::assertNull(Hilos::$db->statusIncidentUpdates->forRequest($id, 'missing'));
        self::assertNull(Hilos::$db->statusIncidentUpdates->forRequest($id, 'request-a'));
        self::assertNull(Hilos::$db->statusIncidentUpdates->forRequest($id + 1000, 'request-A'));
        self::assertSame($teamId, Hilos::$db->statusIncidentUpdates->actions->append(
            $id, StatusUpdateKind::TEAM, StatusSeverity::PARTIAL_OUTAGE, new StatusUpdateText('Investigating.'), 2200, 'request-A',
        )->id);
        try {
            Hilos::$db->statusIncidentUpdates->actions->append(
                $id, StatusUpdateKind::TEAM, StatusSeverity::MAJOR_OUTAGE, new StatusUpdateText('Different.'), 2300, 'request-A',
            );
            self::fail('A request id cannot replace text');
        } catch (StatusIncidentWriteException) {
            self::assertSame('Investigating.', Hilos::$db->statusIncidentUpdates[$teamId]->text);
        }
        self::assertSame($id, Hilos::$db->statusIncidentUpdates[$teamId]->incident?->id);
        self::assertCount(2, Hilos::$db->statusIncidents[$id]->updates);
        self::assertSame([
            ['kind' => 'published', 'severity' => 'major_outage', 'text' => 'Detected automatically.', 'at' => '2000', 'request_id' => null],
            ['kind' => 'team', 'severity' => 'major_outage', 'text' => 'Investigating.', 'at' => '2100', 'request_id' => 'request-A'],
        ], Database::sql(
            'SELECT kind, severity, text, at, request_id FROM hilos_status_incident_update WHERE incident_id = ? ORDER BY at, id', [$id],
        )->rows());
        $this->expectException(ActionsClassException::class);
        Hilos::$db->statusIncidentUpdates[$teamId]->actions;
    }

    /** A request lookup hydrates a matching row that this partial collection never held. */
    public function testRequestLookupReadsUncachedStorage(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::MAJOR_OUTAGE, 1000, 2000);
        Database::sqlRun(
            'INSERT INTO hilos_status_incident_update (incident_id, kind, severity, text, at, request_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$candidate->id, 'team', 'major_outage', 'Not cached', 2100, 'cold-request'],
        );
        $stored = Database::sql('SELECT id FROM hilos_status_incident_update WHERE request_id = ?', ['cold-request'])->rows()[0];
        self::assertSame((int)$stored['id'], Hilos::$db->statusIncidentUpdates->forRequest($candidate->id, 'cold-request')?->id);
        self::assertSame('Not cached', Hilos::$db->statusIncidentUpdates->forRequest($candidate->id, 'cold-request')?->text);
        self::assertCount(1, Hilos::$db->statusIncidents[$candidate->id]->updates);
    }

    /** Even the owning fixture's add right cannot edit or remove a saved timeline row. */
    public function testTimelineOwnerHasOnlyAppendPermission(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::MAJOR_OUTAGE, 1000, 2000);
        $update = Hilos::$db->statusIncidentUpdates->actions->append(
            $candidate->id, StatusUpdateKind::TEAM, StatusSeverity::MAJOR_OUTAGE, new StatusUpdateText('Original'), 2100,
        );
        $object = $update->getObject();
        $object->text = 'Replacement';
        try {
            $object->sync();
            self::fail('An append-only owner cannot edit the timeline');
        } catch (WriteNotAllowedException) {
            self::assertSame('Original', Database::sql(
                'SELECT text FROM hilos_status_incident_update WHERE id = ?', [$update->id],
            )->rows()[0]['text']);
        }
        $this->expectException(WriteNotAllowedException::class);
        $object->delete();
    }

    /** SQL enforces the composite request key even if a writer bypasses the append replay check. */
    public function testRequestUniquenessAndUntrackedSubmissions(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::MAJOR_OUTAGE, 1000, 2000);
        $id = $candidate->id;
        foreach ([null, null, 'one'] as $requestId) {
            Hilos::$db->statusIncidentUpdates->actions->append(
                $id, StatusUpdateKind::TEAM, StatusSeverity::MAJOR_OUTAGE, new StatusUpdateText('Investigating.'), 2100, $requestId,
            );
        }
        self::assertSame('3', Database::sql('SELECT COUNT(*) AS n FROM hilos_status_incident_update')->rows()[0]['n']);
        $this->expectException(DatabaseException::class);
        Database::sqlRun(
            'INSERT INTO hilos_status_incident_update (incident_id, kind, severity, text, at, request_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, 'team', 'major_outage', 'Replacement', 2200, 'one'],
        );
    }

    /** The required parent cannot be deleted while its immutable timeline still refers to it. */
    public function testTimelineForeignKeyRestrictsIncidentDeletion(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::MAJOR_OUTAGE, 1000, 2000);
        Hilos::$db->statusIncidentUpdates->actions->append(
            $candidate->id, StatusUpdateKind::PUBLISHED, StatusSeverity::MAJOR_OUTAGE, new StatusUpdateText('Detected.'), 2000,
        );
        $this->expectException(DatabaseException::class);
        Database::sqlRun('DELETE FROM hilos_status_incident WHERE id = ?', [$candidate->id]);
    }

    /** A caller rollback restores both the persisted state and the local ORM without a partial timeline. */
    public function testRollbackRestoresStateAndTimeline(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::MAJOR_OUTAGE, 1000, 2000);
        $id = $candidate->id;
        Database::transactionStart();
        try {
            Hilos::$db->statusIncidents[$id]->actions->publish(2000);
            Hilos::$db->statusIncidentUpdates->actions->append(
                $id, StatusUpdateKind::PUBLISHED, StatusSeverity::MAJOR_OUTAGE, new StatusUpdateText('Detected.'), 2000,
            );
            self::assertCount(1, Database::sql('SELECT id FROM hilos_status_incident_update WHERE incident_id = ?', [$id])->rows());
        } finally {
            Database::transactionRollback();
        }
        self::assertNull(Hilos::$db->statusIncidents[$id]->publishedAt);
        self::assertNull(Hilos::$db->statusIncidents[$id]->peakSeverity);
        self::assertSame([], Database::sql('SELECT id FROM hilos_status_incident_update WHERE incident_id = ?', [$id])->rows());
        self::assertCount(0, Hilos::$db->statusIncidents[$id]->updates);
        self::assertSame(['published_at' => null, 'peak_severity' => null], Database::sql(
            'SELECT published_at, peak_severity FROM hilos_status_incident WHERE id = ?', [$id],
        )->rows()[0]);
    }

    /** Narrow fixture claims allow adding/updating incidents and only adding timeline rows. */
    public function testForeignWriterCannotCreateOrChangeIncident(): void
    {
        $candidate = Hilos::$db->statusIncidents->actions->candidate('mail', 'Mail', StatusSeverity::MAJOR_OUTAGE, 1000, 2000);
        $foreign = ExecutionContext::push(new ExecutionFrame(agentId: 'foreign-status-writer'));
        try {
            try {
                Hilos::$db->statusIncidents->actions->candidate('other', 'Other', StatusSeverity::MAJOR_OUTAGE, 1000, 2000);
                self::fail('A foreign creator must refuse');
            } catch (CreateNotAllowedException) {
                self::assertSame('1', Database::sql('SELECT COUNT(*) AS n FROM hilos_status_incident')->rows()[0]['n']);
            }
            try {
                Hilos::$db->statusIncidents[$candidate->id]->actions->publish(2000);
                self::fail('A foreign editor must refuse');
            } catch (WriteNotAllowedException) {
                self::assertNull(Hilos::$db->statusIncidents[$candidate->id]->publishedAt);
            }
            $this->expectException(CreateNotAllowedException::class);
            Hilos::$db->statusIncidentUpdates->actions->append(
                $candidate->id, StatusUpdateKind::TEAM, StatusSeverity::MAJOR_OUTAGE, new StatusUpdateText('Foreign'), 2100,
            );
        } finally {
            ExecutionContext::pop($foreign);
        }
    }

    /** Plain text stays plain text and length counts Unicode characters, not bytes. */
    public function testUpdateTextBoundary(): void
    {
        self::assertSame('<b>Unescaped text</b>', new StatusUpdateText(' <b>Unescaped text</b> ')->value);
        self::assertSame(str_repeat('я', 5000), new StatusUpdateText(str_repeat('я', 5000))->value);
        foreach (['   ', str_repeat('я', 5001), "\xff"] as $invalid) {
            try {
                new StatusUpdateText($invalid);
                self::fail('Invalid text must refuse');
            } catch (StatusIncidentWriteException $failure) {
                self::assertSame('Enter an update of 1 to 5000 characters.', $failure->getMessage());
            }
        }
    }
}

/** Adds only the new stubs and local claims outside the managed rollback scope. */
abstract class StatusStorageIntegrationTestCase extends FrameworkRollbackIntegrationTestCase
{
    private const string OWNER_ID = 'status-storage-fixture';

    protected ?StatusMachineRuntime $machineRuntime = null;

    private ?int $statusExecutionToken = null;
    private ?int $machineExecutionToken = null;
    private ?StatusAgent $machineOwner = null;
    private ?HilosNotifier $previousNotify = null;
    private ?SettingsAccessor $previousSetting = null;
    private string $previousApp;
    /** @var list<SourceMirrorSubscriberInterface> Mirrors restored after local failure injection */
    private array $previousMirrors = [];
    /** @var list<string> Stub tables actually created, including partial setup */
    private array $statusTables = [];

    /** Uses production ORM chains without activating an incomplete production feature. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousNotify = Hilos::$notify;
        $this->previousSetting = Hilos::$setting;
        $this->previousApp = Hilos::appClass();
        $this->previousMirrors = (new ReflectionProperty(SourceChangeBus::class, 'mirrors'))->getValue();
        Hilos::$notify = null;
        parent::prepareIntegrationEnvironment();
        foreach ([EntityStatusIncident::_table, EntityStatusIncidentUpdate::_table] as $table) {
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '.sql'));
            $this->statusTables[] = $table;
        }
        Schema::reset();
        Schema::initialize();
        OwnershipDeclaration::claimDb(StatusStorageFixtureOwner::class, self::OWNER_ID);
        $this->statusExecutionToken = ExecutionContext::push(new ExecutionFrame(agentId: self::OWNER_ID));
        Hilos::$db = new StatusStorageDbContext();
        Hilos::$db->configure();
    }

    /** Rolls the new schema and claims back out before restoring the inherited environment. */
    protected function releaseIntegrationEnvironment(): void
    {
        $failures = [];
        try {
            foreach (array_reverse($this->statusTables) as $table) {
                try {
                    Database::sqlRun(
                        file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '_down.sql'),
                    );
                } catch (Throwable $failure) {
                    $failures[] = $failure;
                }
            }
        } finally {
            (new ReflectionProperty(SourceChangeBus::class, 'mirrors'))->setValue(null, $this->previousMirrors);
            if ($this->machineOwner !== null) {
                TruthSourceRegistry::unregisterAgent($this->machineOwner->getId());
                RtTruthSourceRegistry::unregisterAgent($this->machineOwner->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->machineOwner->getId()));
                $this->machineOwner = null;
            }
            if ($this->machineExecutionToken !== null) {
                ExecutionContext::pop($this->machineExecutionToken);
                $this->machineExecutionToken = null;
            }
            Hilos::$setting = $this->previousSetting;
            (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, $this->previousApp);
            $this->statusTables = [];
            TruthSourceRegistry::unregisterAgent(self::OWNER_ID);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::OWNER_ID));
            if ($this->statusExecutionToken !== null) {
                ExecutionContext::pop($this->statusExecutionToken);
                $this->statusExecutionToken = null;
            }
            Hilos::$notify = $this->previousNotify;
            try {
                parent::releaseIntegrationEnvironment();
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }
        if ($failures !== []) {
            throw $failures[0];
        }
    }

    /**
     * @param class-string<Hilos> $app Component catalog used by the actual Status owner
     * @return StatusAgent Started production owner with real scoped claims
     */
    protected function startMachine(string $app = StatusMachineHilos::class): StatusAgent
    {
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, $app);
        Hilos::$setting = new SettingsAccessor(StatusSettingsCatalog::class);
        Hilos::$sr = new SignalRouter();
        $this->machineRuntime = new StatusMachineRuntime();
        $this->machineRuntime->mountFeatureRuntime([new StatusFeature()]);
        $this->machineRuntime->configure();
        $this->machineRuntime->bindStateCollectionNames();
        Hilos::$rt = $this->machineRuntime;
        return $this->restartMachine();
    }

    /** @return StatusAgent A new instance retaining durable state and discarding all observation buffers */
    protected function restartMachine(): StatusAgent
    {
        if ($this->machineExecutionToken !== null) {
            ExecutionContext::pop($this->machineExecutionToken);
        }
        if ($this->machineOwner !== null) {
            TruthSourceRegistry::unregisterAgent($this->machineOwner->getId());
            RtTruthSourceRegistry::unregisterAgent($this->machineOwner->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->machineOwner->getId()));
        }
        $this->machineOwner = new StatusAgent();
        OwnershipDeclaration::claimAll($this->machineOwner);
        $this->machineExecutionToken = ExecutionContext::push(new ExecutionFrame(agentId: $this->machineOwner->getId()));
        $this->machineOwner->onStart();
        return $this->machineOwner;
    }

    /**
     * @param StatusAgent $owner Production owner
     * @param int $at Local acceptance moment in epoch milliseconds
     * @param list<?bool> $healthy Healthy, absent, or unavailable process roster for each online node
     */
    protected function freshReport(StatusAgent $owner, int $at, array $healthy): void
    {
        $this->report($owner, true, $at - 1, $healthy);
        $this->report($owner, false, $at, $healthy);
    }

    /**
     * @param StatusAgent $owner Production owner
     * @param bool $snapshot Full collector membership or changed whole slots
     * @param int $at Local acceptance clock, also used only as a report identity
     * @param list<?bool> $healthy Healthy, absent, or unavailable process roster per online node
     */
    protected function report(StatusAgent $owner, bool $snapshot, int $at, array $healthy): void
    {
        $nodes = [];
        foreach ($healthy as $index => $present) {
            $nodeId = 'n' . $index;
            $processes = $present === null ? null : new DaemonProcessRoster([
                new DaemonWorkerPicture(1, 'regular', null, 4096,
                    $present ? [new DaemonAgentPicture('probe', 'node', 'node')] : []),
            ], null, 0);
            $nodes[] = new ClusterDaemonNodeView($nodeId, true,
                new ClusterDaemonNodeSlot($nodeId, new NodeDaemonPicture($nodeId, NodeRole::Master, 9999999999, $processes), $at));
        }
        $portion = new DaemonClusterPicturePortionSignalData($snapshot, $nodes);
        $owner->applyPicturePortion(DaemonClusterPicturePortionSignalData::fromArray($portion->toArray()), $at);
    }

    /**
     * @param int $seconds New setting for the next deadline
     * @param bool $validate False injects corrupt persisted configuration through the owning fixture's ORM
     */
    protected function delay(int $seconds, bool $validate = true): void
    {
        $token = ExecutionContext::push(new ExecutionFrame(agentId: self::OWNER_ID));
        try {
            if (!isset(Hilos::$db->settings[StatusSettingsCatalog::PUBLISH_DELAY_SECONDS])) {
                Hilos::$db->settings->actions->add(StatusSettingsCatalog::PUBLISH_DELAY_SECONDS, 300, StatusSettingsCatalog::getCatalog());
            }
            if ($validate) {
                Hilos::$db->settings[StatusSettingsCatalog::PUBLISH_DELAY_SECONDS]->actions->updateValue($seconds);
            } else {
                $setting = Hilos::$db->settings[StatusSettingsCatalog::PUBLISH_DELAY_SECONDS]->getObject();
                $setting->value = (string)$seconds;
                $setting->sync();
            }
        } finally {
            ExecutionContext::pop($token);
        }
    }

    /** @return list<array<string, ?string>> Independent persisted incident rows */
    protected function storedIncidents(): array
    {
        return Database::sql('SELECT * FROM hilos_status_incident ORDER BY id')->rows();
    }

    /** @return list<array<string, string>> Independent immutable timeline rows */
    protected function storedUpdates(): array
    {
        return Database::sql('SELECT kind, severity, text, at FROM hilos_status_incident_update ORDER BY id')->rows();
    }

    /** @return list<string> Queued signal names; transactional announcements remain held by the rollback root */
    protected function drainMachineSignals(): array
    {
        $names = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $names[] = $frame->signalName->getName();
        }
        return $names;
    }
}

/** Storage-only fixture, independent of the running project's feature declaration. */
final class StatusStorageDbContext extends HilosDbContext
{
    /** Binds the two storage chains under their framework keys and key-based load strategy. */
    public function configure(): void
    {
        parent::configure();
        $this->_objectCollections[self::statusIncidents] = ObjectStatusIncidents::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusIncidents, StatusMachineIncidents::class, StatusIncidentsActions::class, StatusIncidentActions::class);
        $this->_objectCollections[self::statusIncidentUpdates] = ObjectStatusIncidentUpdates::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusIncidentUpdates, StatusIncidentUpdates::class, StatusIncidentUpdatesActions::class);
    }
}

/** Reads only the activation switch needed by the production context mount. */
abstract class StatusProductionMountHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::STATUS, HilosFeature::DAEMON, HilosFeature::NOTIFICATION_DELIVERY];
}

/** Uses the production conditional mount without any local registration. */
final class StatusProductionDbContext extends HilosDbContext
{
}

/** Same operation widths as the planned Status owner; no blanket integration grant. */
final class StatusStorageFixtureOwner implements TruthSourceOwner
{
    public const array OWNS_DB = [
        HilosDbContext::settings => TruthSourceOperation::ALL,
        HilosDbContext::statusIncidents => [TruthSourceOperation::Add, TruthSourceOperation::Update],
        HilosDbContext::statusIncidentUpdates => [TruthSourceOperation::Add],
    ];

    /** @return TruthSourceOperations Explicit claims above carry their own narrower operations */
    public static function defaultTruthSourceOperations(): TruthSourceOperations
    {
        return TruthSourceOperations::all();
    }
}

/** Instrumentation observes the query boundary while retaining the real SQL loader. */
final class StatusMachineIncidents extends StatusIncidents
{
    /** @var list<list<int|string>> Column and keys of each bounded current selection */
    public array $selections = [];

    /**
     * @param string $column Persisted indexed column
     * @param int|string ...$values Portion's keys
     * @return static Real ORM selection
     */
    public function whereColumnIn(string $column, int|string ...$values): static
    {
        $this->selections[] = [$column, ...$values];
        return parent::whereColumnIn($column, ...$values);
    }
}

/** Fails once after the real timeline write, with the component's RT edit already inside the same transaction. */
final class StatusMachineFailingMirror implements SourceMirrorSubscriberInterface
{
    public bool $armed = true;

    /**
     * @param SourceChange $change Actual ORM mutation
     * @param SourceChangeProvenance $provenance Mutation origin, unused
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->armed && $change->sourceKey === HilosDbContext::statusIncidentUpdates && $change->mutationType === TableMutationType::Create) {
            $this->armed = false;
            throw new RuntimeException('Injected failure after timeline insertion.');
        }
    }
}

/** NODE component permits both Partial and Major facts from the real slot normalizer. */
abstract class StatusMachineHilos extends Hilos
{
    public const array AGENTS = ['probe' => [AgentRegistryKey::SCOPE => AgentScope::NODE]];
    protected const array FEATURES = [HilosFeature::STATUS];
    protected const array STATUS_COMPONENTS = [
        'service' => ['name' => 'Service', 'description' => null, 'agentType' => 'probe'],
    ];
}

final class StatusMachineRuntime extends RtContext
{
    /** Feature mounting owns every runtime binding used here. */
    public function configure(): void
    {
    }

    /** @param string $phase Isolated freeze phase, without starting a destructive operation */
    public function phase(string $phase): void
    {
        $freeze = ProtectedModeRuntime::create();
        $freeze->phase = $phase;
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $freeze);
    }
}

/** More than one reconciliation portion, with declaration order made explicit. */
abstract class StatusManyComponentsHilos extends StatusMachineHilos
{
    protected const array STATUS_COMPONENTS = [
        'c1' => ['name' => 'Component 1', 'description' => null, 'agentType' => 'probe'],
        'c2' => ['name' => 'Component 2', 'description' => null, 'agentType' => 'probe'],
        'c3' => ['name' => 'Component 3', 'description' => null, 'agentType' => 'probe'],
        'c4' => ['name' => 'Component 4', 'description' => null, 'agentType' => 'probe'],
        'c5' => ['name' => 'Component 5', 'description' => null, 'agentType' => 'probe'],
        'c6' => ['name' => 'Component 6', 'description' => null, 'agentType' => 'probe'],
        'c7' => ['name' => 'Component 7', 'description' => null, 'agentType' => 'probe'],
        'c8' => ['name' => 'Component 8', 'description' => null, 'agentType' => 'probe'],
        'c9' => ['name' => 'Component 9', 'description' => null, 'agentType' => 'probe'],
        'c10' => ['name' => 'Component 10', 'description' => null, 'agentType' => 'probe'],
        'c11' => ['name' => 'Component 11', 'description' => null, 'agentType' => 'probe'],
        'c12' => ['name' => 'Component 12', 'description' => null, 'agentType' => 'probe'],
        'c13' => ['name' => 'Component 13', 'description' => null, 'agentType' => 'probe'],
        'c14' => ['name' => 'Component 14', 'description' => null, 'agentType' => 'probe'],
        'c15' => ['name' => 'Component 15', 'description' => null, 'agentType' => 'probe'],
        'c16' => ['name' => 'Component 16', 'description' => null, 'agentType' => 'probe'],
        'c17' => ['name' => 'Component 17', 'description' => null, 'agentType' => 'probe'],
        'c18' => ['name' => 'Component 18', 'description' => null, 'agentType' => 'probe'],
        'c19' => ['name' => 'Component 19', 'description' => null, 'agentType' => 'probe'],
        'c20' => ['name' => 'Component 20', 'description' => null, 'agentType' => 'probe'],
        'c21' => ['name' => 'Component 21', 'description' => null, 'agentType' => 'probe'],
        'c22' => ['name' => 'Component 22', 'description' => null, 'agentType' => 'probe'],
        'c23' => ['name' => 'Component 23', 'description' => null, 'agentType' => 'probe'],
        'c24' => ['name' => 'Component 24', 'description' => null, 'agentType' => 'probe'],
        'c25' => ['name' => 'Component 25', 'description' => null, 'agentType' => 'probe'],
        'c26' => ['name' => 'Component 26', 'description' => null, 'agentType' => 'probe'],
        'c27' => ['name' => 'Component 27', 'description' => null, 'agentType' => 'probe'],
        'c28' => ['name' => 'Component 28', 'description' => null, 'agentType' => 'probe'],
        'c29' => ['name' => 'Component 29', 'description' => null, 'agentType' => 'probe'],
        'c30' => ['name' => 'Component 30', 'description' => null, 'agentType' => 'probe'],
        'c31' => ['name' => 'Component 31', 'description' => null, 'agentType' => 'probe'],
        'c32' => ['name' => 'Component 32', 'description' => null, 'agentType' => 'probe'],
        'c33' => ['name' => 'Component 33', 'description' => null, 'agentType' => 'probe'],
        'c34' => ['name' => 'Component 34', 'description' => null, 'agentType' => 'probe'],
        'c35' => ['name' => 'Component 35', 'description' => null, 'agentType' => 'probe'],
    ];
}

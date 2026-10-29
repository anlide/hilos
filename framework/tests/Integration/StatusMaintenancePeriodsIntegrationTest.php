<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\OrmForgetRefusedException;
use Hilos\Database\Object\Collection\StatusWorkComponents as ObjectStatusWorkComponents;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusComponentState;
use Hilos\Status\StatusMaintenanceObservation;
use Hilos\Status\StatusMaintenanceObservationStore;
use Hilos\Status\StatusPublicComponent;
use Hilos\Status\StatusPublicMaintenancePeriod;
use Hilos\Status\StatusPublicFiles;
use Hilos\Status\StatusPublicSnapshot;
use Hilos\Status\StatusPublicWork;
use Hilos\Status\StatusSettingsCatalog;
use Hilos\Tests\Fixtures\StatusPublicVolume;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/** Real owner, commits and filesystem transit; intentionally outside a synthetic rollback root. */
final class StatusMaintenancePeriodsIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = ['hilos_setting', 'hilos_status_incident', 'hilos_status_incident_update',
        'hilos_status_work', 'hilos_status_work_component', 'hilos_status_maintenance_period'];

    private StatusAgent $owner;
    private StatusMaintenanceLifecycleRuntime $runtime;
    private StatusPublicVolume $volume;
    private int $ownerFrame;
    private string $previousApp;
    private array $previousGlobals;
    private array $previousBus = [];
    private array $previousInterests = [];
    private array $previousClaims = [];
    private string|false $previousAppEnv;
    private int $now;

    /** Real schema and actual feature-owned runtime on an isolated temporary Status volume. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousGlobals = [Hilos::$db, Hilos::$rt, Hilos::$sr, Hilos::$env, Hilos::$setting, Hilos::$notify];
        foreach (['mirrors', 'reactions', 'provenance'] as $property) {
            $this->previousBus[$property] = new ReflectionProperty(SourceChangeBus::class, $property)->getValue();
        }
        foreach (['byConsumer', 'states', 'readsWhatIsDelivered'] as $property) {
            $this->previousInterests[$property] = new ReflectionProperty(SourceInterestRegistry::class, $property)->getValue();
        }
        foreach ([TruthSourceRegistry::class, RtTruthSourceRegistry::class] as $registry) {
            $this->previousClaims[$registry] = new ReflectionProperty($registry, 'sources')->getValue();
        }
        $this->previousAppEnv = getenv(EnvConstants::APP_ENV->name);
        putenv(EnvConstants::APP_ENV->name . '=test');
        try {
            $this->mountEnvironment();
        } catch (Throwable $failure) {
            $this->tearDown();
            throw $failure;
        }
    }

    /** Restores source registries and globals after real commits, including failed-import cases. */
    protected function tearDown(): void
    {
        try {
            $this->releaseOwner();
            foreach (array_reverse(self::TABLES) as $table) {
                $this->stub($table, true);
            }
        } finally {
            Schema::reset();
            try {
                if (isset($this->volume)) {
                    $this->volume->close();
                }
            } finally {
                foreach ($this->previousBus as $property => $value) {
                    new ReflectionProperty(SourceChangeBus::class, $property)->setValue(null, $value);
                }
                foreach ($this->previousInterests as $property => $value) {
                    new ReflectionProperty(SourceInterestRegistry::class, $property)->setValue(null, $value);
                }
                foreach ($this->previousClaims as $registry => $sources) {
                    new ReflectionProperty($registry, 'sources')->setValue(null, $sources);
                }
                new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
                [Hilos::$db, Hilos::$rt, Hilos::$sr, Hilos::$env, Hilos::$setting, Hilos::$notify] = $this->previousGlobals;
                putenv($this->previousAppEnv === false ? EnvConstants::APP_ENV->name
                    : EnvConstants::APP_ENV->name . '=' . $this->previousAppEnv);
                parent::tearDown();
            }
        }
    }

    public function testColdResetRefusalAfterOwnerStopStillReleasesTheEnvironment(): void
    {
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $this->releaseOwner();
        putenv(EnvConstants::APP_ENV->name . '=prod');
        $this->expectException(OrmForgetRefusedException::class);
        Hilos::$db->forgetLoadedDataForTest();
    }

    public function testAnnouncedFreezeOutlivesWindowAndConsumesBeforeExpiry(): void
    {
        $work = Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks->actions->create(
            'Upgrade', 'Public description', $this->now, $this->now + 2000, $this->now - 2000, ['service'],
        ));
        $this->settle($this->now - 1000);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVATING, intdiv($this->now, 1000));
        $this->owner->tickAt($this->now + 100);
        $current = Hilos::$rt->hilosStatusMaintenance['current'];
        self::assertTrue($current->available);
        self::assertTrue($current->active);
        self::assertTrue($current->announcementKnown);
        self::assertSame($work->publicId, $current->workPublicId);
        self::assertSame($this->now, $current->startedAt);
        $id = $current->periodId;
        $database = Hilos::$db;
        $settings = Hilos::$setting;
        Hilos::$db = null;
        Hilos::$setting = null;
        try {
            foreach ([ProtectedModeRuntime::PHASE_ACTIVE, ProtectedModeRuntime::PHASE_VERIFYING,
                ProtectedModeRuntime::PHASE_DEACTIVATING] as $index => $phase) {
                $this->runtime->phase($phase, intdiv($this->now, 1000) + $index + 1);
                $this->settle($this->now + 3000 + $index * 1000);
                self::assertTrue($current->active);
                self::assertSame($id, $current->periodId);
                self::assertSame('Upgrade', $current->title);
                $snapshot = (new StatusPublicFiles())->restore();
                self::assertSame($id, $snapshot->maintenancePeriods[0]->id);
                self::assertSame('Upgrade', $snapshot->maintenancePeriods[0]->title);
            }
        } finally {
            Hilos::$db = $database;
            Hilos::$setting = $settings;
        }
        self::assertSame([], $this->rows());
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle($this->now + 10000);
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame($id, $rows[0]['public_id']);
        self::assertSame($this->now + 10000, (int)$rows[0]['ended_at']);
        self::assertSame($rows[0]['ended_at'], $rows[0]['completion_observed_at']);
        $stored = Database::sql('SELECT consumed_at, expired_at FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0];
        self::assertSame($this->now, (int)$stored['consumed_at']);
        self::assertNull($stored['expired_at']);
        self::assertSame([], (new StatusMaintenanceObservationStore())->discover());
    }

    /** @return iterable<string, array{bool}> Both proven classifications in the same freeze second */
    public static function subsecondAnnouncements(): iterable
    {
        yield 'announced' => [true];
        yield 'unannounced' => [false];
    }

    /** @param bool $announced Whether a work covers the seconds-precision start */
    #[DataProvider('subsecondAnnouncements')]
    public function testAnInactiveObservationProvesASnapshotPublishedWithinTheFreezeSecond(bool $announced): void
    {
        $work = $announced ? Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks->actions->create(
            'Subsecond upgrade', null, $this->now, $this->now + 10000, $this->now - 1000, ['service'],
        )) : null;
        $this->settle($this->now + 100);
        self::assertGreaterThanOrEqual($this->now + 100, (new StatusPublicFiles())->restore()->generatedAt);
        $this->owner->tickAt($this->now + 200);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $database = Hilos::$db;
        $settings = Hilos::$setting;
        Hilos::$db = null;
        Hilos::$setting = null;
        try {
            $this->owner->tickAt($this->now + 300);
            self::assertTrue(Hilos::$rt->hilosStatusMaintenance['current']->announcementKnown);
            self::assertSame($work?->publicId, Hilos::$rt->hilosStatusMaintenance['current']->workPublicId);
            self::assertSame($this->now, Hilos::$rt->hilosStatusMaintenance['current']->startedAt);
            self::assertSame([], $this->mailFrames());
        } finally {
            Hilos::$db = $database;
            Hilos::$setting = $settings;
        }
    }

    /** @return iterable<string, array{string}> No first classification without a fully proven pair */
    public static function unprovenSubsecondSources(): iterable
    {
        yield 'published after last inactive' => ['late'];
        yield 'publication in progress' => ['publishing'];
        yield 'build in progress' => ['building'];
        yield 'dirty after expiry' => ['dirty'];
        yield 'runtime gap' => ['unknown'];
    }

    /** @param string $source Real publisher, builder or runtime transition that invalidates proof */
    #[DataProvider('unprovenSubsecondSources')]
    public function testSubsecondToleranceDoesNotClassifyUnprovenSources(string $source): void
    {
        $this->settle($this->now - 30000);
        if ($source === 'dirty') {
            Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks->actions->create(
                'Expired since publication', null, $this->now - 1000, $this->now, $this->now - 2000, ['service'],
            ));
        }
        $this->owner->reconcileMaintenanceBeforeWrite($this->now + 200);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $files = new ReflectionProperty(StatusAgent::class, 'publicFiles')->getValue($this->owner);
        $snapshot = new StatusPublicSnapshot('after-inactive', $this->now + 250, 30, 120, [], [], []);
        if ($source === 'publishing' || $source === 'late') {
            $files->begin($snapshot);
            if ($source === 'late') {
                for ($step = 0; $files->isPublishing() && $step < 100; ++$step) {
                    $files->advance($this->now + 250);
                }
                self::assertFalse($files->isPublishing());
            }
        } elseif ($source === 'building') {
            new ReflectionProperty(StatusAgent::class, 'publicBuilder')->getValue($this->owner)->beginDetached($snapshot);
        } elseif ($source === 'unknown') {
            $this->runtime->sourceUnavailable = true;
            $this->owner->tickAt($this->now + 250);
            $this->runtime->sourceUnavailable = false;
        }
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $this->owner->tickAt($this->now + 300);
        self::assertFalse(Hilos::$rt->hilosStatusMaintenance['current']->announcementKnown);
        self::assertNull(Hilos::$rt->hilosStatusMaintenance['current']->workPublicId);
    }

    /** @return iterable<string, array{int, bool}> Real SQL and memory entrances to the selected component bridge */
    public static function componentStrategies(): iterable
    {
        yield 'lazy key' => [Objects::LAZY_STRATEGY_KEY, false];
        yield 'preloaded key' => [Objects::LAZY_STRATEGY_KEY, true];
        yield 'whole on first read' => [Objects::LAZY_STRATEGY_NONE, false];
        yield 'batch' => [Objects::LAZY_STRATEGY_BATCH, false];
        yield 'full on access' => [Objects::LAZY_STRATEGY_FULL_ON_ACCESS, false];
    }

    /**
     * @param int $strategy Real ORM loading policy
     * @param bool $preload Whether the holder has declared the lazy collection complete
     */
    #[DataProvider('componentStrategies')]
    public function testEditedComponentSetReplaysAfterCommitAndRestartWithoutHoldingMutations(int $strategy, bool $preload): void
    {
        Hilos::$db->statusWorkComponents->setObjectCollection(ObjectStatusWorkComponents::initDB($strategy));
        if ($preload) {
            Hilos::$db->statusWorkComponents->getObjectCollection()->preloadAll();
        }
        $work = Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks->actions->create(
            'Multi-component upgrade', null, $this->now, $this->now + 10000, $this->now - 3000, ['web'],
        ));
        $webId = $work->components->first()->id;
        Database::transaction(DatabaseNames::PRIMARY, fn() => $work->actions->change(
            $work->title, null, $work->startsAt, $work->endsAt, $this->now - 2000, ['api', 'web'],
        ));
        self::assertSame($webId, (int)Database::sql(
            'SELECT id FROM hilos_status_work_component WHERE work_id = ? AND component_key = ?', [$work->id, 'web'],
        )->rows()[0]['id']);
        $this->settle($this->now - 1000);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $this->settle($this->now);
        $store = new StatusMaintenanceObservationStore();
        $id = Hilos::$rt->hilosStatusMaintenance['current']->periodId;
        $observation = $store->load($id . '.json')->complete($this->now + 1000, true);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle($this->now + 1000);
        self::assertCount(1, $this->rows());
        $persisted = $this->rows();
        $store->save($observation); // Replay the transit left by a crash before unlink.
        $this->releaseOwner();
        Hilos::$db->forgetLoadedDataForTest();
        $this->startOwner();
        $this->settle($this->now + 2000);
        self::assertSame($persisted, $this->rows());
        self::assertSame([], $store->discover());
        $this->owner->reconcileMaintenanceBeforeWrite($this->now + 2100);

        $different = StatusMaintenanceObservation::start($this->now, $this->now, true,
            new StatusPublicWork($work->publicId, $work->title, null, ['web'], $this->now, $this->now + 10000));
        $store->save(new StatusMaintenanceObservation(new StatusPublicMaintenancePeriod(
            $id, $work->publicId, true, $work->title, ['web'], $this->now, $this->now, null, $this->now + 1000,
        ), $different->work));
        $this->releaseOwner();
        $this->startOwner();
        $this->settle($this->now + 4000);
        self::assertSame($persisted, $this->rows());
        self::assertSame([$id . '.json'], $store->discover(), 'A different set remains a conflicting observation');
    }

    /** @return iterable<string, array{string}> Restored terminal or absent rows never acquire consumption */
    public static function restoredTerminalStates(): iterable
    {
        yield 'canceled' => ['canceled_at'];
        yield 'expired' => ['expired_at'];
        yield 'missing' => ['missing'];
    }

    /** @param string $state Restored lifecycle column, or a missing announcement */
    #[DataProvider('restoredTerminalStates')]
    public function testRecoveredProofDoesNotConsumeTerminalOrMissingRows(string $state): void
    {
        $work = Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks->actions->create(
            'Original', null, $this->now, $this->now + 10000, $this->now - 2000, ['service'],
        ));
        $observation = StatusMaintenanceObservation::start($this->now, $this->now, true, StatusPublicWork::fromWork($work))
            ->complete($this->now + 1000, false);
        if ($state === 'missing') {
            Database::sql('DELETE FROM hilos_status_work WHERE id = ?', [$work->id]);
        } else {
            Database::sql('UPDATE hilos_status_work SET ' . $state . ' = ? WHERE id = ?', [$this->now - 500, $work->id]);
        }
        Hilos::$db->forgetLoadedDataForTest();
        (new StatusMaintenanceObservationStore())->save($observation);
        $this->releaseOwner();
        $this->startOwner();
        $this->settle($this->now + 2000);
        self::assertCount(1, $this->rows());
        self::assertSame($work->publicId, $this->rows()[0]['work_public_id']);
        self::assertSame([], (new StatusMaintenanceObservationStore())->discover());
        if ($state === 'missing') {
            self::assertNull($this->rows()[0]['work_id']);
        } else {
            self::assertNull(Database::sql('SELECT consumed_at FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0]['consumed_at']);
        }
    }

    public function testObservedConsumptionRequiresATransactionAndKeepsALaterEditClock(): void
    {
        $work = Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks->actions->create(
            'Observed work', null, $this->now, $this->now + 5000, $this->now - 2000, ['service'],
        ));
        $observation = StatusMaintenanceObservation::start($this->now, $this->now, true, StatusPublicWork::fromWork($work));
        Database::transaction(DatabaseNames::PRIMARY, fn() => $work->actions->change(
            'Later restored content', null, $this->now + 10000, $this->now + 20000, $this->now + 2000, ['service'],
        ));
        $before = Database::sql('SELECT * FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0];
        try {
            $work->actions->consumeObservation($observation);
            self::fail('Observation consumption must join its owner transaction');
        } catch (DatabaseException) {
            self::assertSame($before, Database::sql('SELECT * FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0]);
        }
        $foreign = StatusMaintenanceObservation::start($this->now, $this->now, true, new StatusPublicWork(
            str_repeat('f', 32), 'Observed work', null, ['service'], $this->now, $this->now + 5000,
        ));
        try {
            Database::transaction(DatabaseNames::PRIMARY, fn() => $work->actions->consumeObservation($foreign));
            self::fail('A saved observation must not consume another public identity');
        } catch (ValidationException) {
            self::assertSame($before, Database::sql('SELECT * FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0]);
        }
        Database::transaction(DatabaseNames::PRIMARY, fn() => $work->actions->consumeObservation($observation));
        $stored = Database::sql('SELECT * FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0];
        self::assertSame($this->now, (int)$stored['consumed_at']);
        self::assertSame($this->now + 2000, (int)$stored['updated_at']);
        self::assertSame(3, (int)$stored['version']);
        self::assertSame('Later restored content', $stored['title']);
        self::assertSame($this->now + 10000, (int)$stored['starts_at']);
    }

    public function testUnsortedActivePublicPairRecoversItsAnnouncementSnapshot(): void
    {
        $work = Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks->actions->create(
            'Recovered component set', null, $this->now - 2000, $this->now + 10000, $this->now - 3000, ['api', 'web'],
        ));
        $announcement = StatusPublicWork::fromWork($work);
        $active = StatusMaintenanceObservation::start($this->now - 1000, $this->now - 500, true, $announcement);
        $snapshot = new StatusPublicSnapshot('unsorted-active-recovery', $this->now - 500, 30, 120, [], [], [],
            [$announcement], [$active->period]);
        $this->publish($snapshot);
        $data = $snapshot->toArray();
        $data['scheduledWorks'][0]['componentKeys'] = ['web', 'api'];
        FsPath::write(Hilos::$fs->status['status.json']->getPath(), json_encode($data, JSON_THROW_ON_ERROR));
        $this->releaseOwner();
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $this->startOwner();
        $this->settle($this->now);
        self::assertSame($active->period->id, Hilos::$rt->hilosStatusMaintenance['current']->periodId);
        self::assertSame($work->publicId, Hilos::$rt->hilosStatusMaintenance['current']->workPublicId);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle($this->now + 1000);
        self::assertCount(1, $this->rows());
        self::assertSame($this->now - 1000, (int)Database::sql(
            'SELECT consumed_at FROM hilos_status_work WHERE id = ?', [$work->id],
        )->rows()[0]['consumed_at']);
        self::assertSame([], (new StatusMaintenanceObservationStore())->discover());
    }

    public function testRestoreOfAnOlderWindowConsumesTheSamePublicIdentityWithoutRestoringItsContent(): void
    {
        $work = Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks->actions->create(
            'Original future title', 'Original description', $this->now + 10000, $this->now + 20000,
            $this->now - 3000, ['service'],
        ));
        $original = Database::sql('SELECT * FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0];
        Database::transaction(DatabaseNames::PRIMARY, fn() => $work->actions->change(
            'Current title', 'Current description', $this->now, $this->now + 5000, $this->now - 2000, ['service'],
        ));
        $this->settle($this->now - 1000);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $this->settle($this->now);
        self::assertSame($work->publicId, Hilos::$rt->hilosStatusMaintenance['current']->workPublicId);
        $store = new StatusMaintenanceObservationStore();
        $id = Hilos::$rt->hilosStatusMaintenance['current']->periodId;
        $observation = $store->load($id . '.json');
        $this->releaseOwner();
        Database::sql('UPDATE hilos_status_work SET title = ?, description = ?, starts_at = ?, ends_at = ?, updated_at = ?, version = ? WHERE id = ?',
            [$original['title'], $original['description'], $original['starts_at'], $original['ends_at'],
                $original['updated_at'], $original['version'], $work->id]);
        Hilos::$db->forgetLoadedDataForTest();
        $this->startOwner();
        SourceChangeBus::subscribe(new StatusMaintenanceFailingMirror());
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->owner->tickAt($this->now + 1000);
        self::assertSame([], $this->rows());
        self::assertNull(Database::sql('SELECT consumed_at FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0]['consumed_at']);
        self::assertSame([$id . '.json'], $store->discover(), 'Failed import rolls consumption back and retains its proof');
        $this->settle($this->now + 2100);
        $stored = Database::sql('SELECT * FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0];
        self::assertSame($this->now, (int)$stored['consumed_at']);
        foreach (['public_id', 'title', 'description', 'starts_at', 'ends_at', 'created_at'] as $field) {
            self::assertSame($original[$field], $stored[$field]);
        }
        self::assertSame(2, (int)$stored['version']);
        self::assertSame(0, Hilos::$db->statusWorks->summary($this->now + 1000)->upcoming);
        self::assertCount(0, Hilos::$db->statusWorks->upcomingPortion($this->now + 1000, null, 32));
        self::assertSame(0, Hilos::$db->statusWorks->queryPage(new TableQueryDTO(limit: 32))[TableConstants::RESULT_KEY_TOTAL_COUNT]);
        self::assertCount(1, $this->rows());
        self::assertSame('Current title', $this->rows()[0]['title']);
        $persisted = $this->rows();
        $store->save($observation->complete($this->now + 1000, false));
        $this->releaseOwner();
        $this->startOwner();
        $this->settle($this->now + 4000);
        self::assertSame($persisted, $this->rows());
        self::assertSame([], $store->discover());
        foreach (['change', 'cancel'] as $action) {
            try {
                if ($action === 'change') {
                    Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks[$work->id]->actions->change(
                        'Forbidden', null, $this->now + 10000, $this->now + 20000, $this->now + 3000, ['service'],
                    ));
                } else {
                    Hilos::$db->statusWorks[$work->id]->actions->cancel($this->now + 3000);
                }
                self::fail('Consumed announcements must remain terminal');
            } catch (ValidationException) {
                self::assertSame($stored, Database::sql('SELECT * FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0]);
            }
        }
    }

    public function testRestartAndMissingRuntimeNeverInventAnExactEndOrAnotherPeriod(): void
    {
        $this->settle($this->now - 1000);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $this->settle($this->now);
        $id = Hilos::$rt->hilosStatusMaintenance['current']->periodId;
        self::assertTrue(Hilos::$rt->hilosStatusMaintenance['current']->announcementKnown);
        self::assertNull(Hilos::$rt->hilosStatusMaintenance['current']->workPublicId);
        $this->releaseOwner();
        $this->startOwner();
        $this->runtime->phase(ProtectedModeRuntime::PHASE_VERIFYING, intdiv($this->now, 1000) + 50);
        $this->settle($this->now + 50000);
        self::assertSame($id, Hilos::$rt->hilosStatusMaintenance['current']->periodId);
        $this->runtime->sourceUnavailable = true;
        $this->owner->tickAt($this->now + 51000);
        self::assertFalse(Hilos::$rt->hilosStatusMaintenance['current']->available);
        self::assertTrue(Hilos::$rt->hilosStatusMaintenance['current']->active);
        self::assertSame([], $this->rows());
        $this->runtime->sourceUnavailable = false;
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle($this->now + 60000);
        self::assertCount(1, $this->rows());
        self::assertSame($id, $this->rows()[0]['public_id']);
        self::assertNull($this->rows()[0]['ended_at']);
        self::assertSame($this->now + 60000, (int)$this->rows()[0]['completion_observed_at']);
    }

    /** @return iterable<string, array{string}> Every occupied phase can supply the first actual fact */
    public static function busyPhases(): iterable
    {
        foreach ([ProtectedModeRuntime::PHASE_ACTIVATING, ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::PHASE_VERIFYING, ProtectedModeRuntime::PHASE_DEACTIVATING] as $phase) {
            yield $phase => [$phase];
        }
    }

    /** @param string $phase First occupied phase seen by a cold owner */
    #[DataProvider('busyPhases')]
    public function testColdBusyOwnerHasUnknownAnnouncementWithoutDbSettingsOrMail(string $phase): void
    {
        $this->releaseOwner();
        $this->runtime->phase($phase, intdiv($this->now, 1000));
        $database = Hilos::$db;
        $settings = Hilos::$setting;
        Hilos::$db = null;
        Hilos::$setting = null;
        try {
            $this->startOwner();
            $this->settle($this->now);
            self::assertTrue(Hilos::$rt->hilosStatusMaintenance['current']->active);
            self::assertFalse(Hilos::$rt->hilosStatusMaintenance['current']->announcementKnown);
            self::assertNull(Hilos::$rt->hilosStatusMaintenance['current']->workPublicId);
            $id = Hilos::$rt->hilosStatusMaintenance['current']->periodId;
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $id);
            $snapshot = (new StatusPublicFiles())->restore();
            self::assertSame($id, $snapshot->maintenancePeriods[0]->id);
            self::assertFalse($snapshot->maintenancePeriods[0]->announcementKnown);
            $this->runtime->sourceUnavailable = true;
            $this->owner->tickAt($this->now + 40000);
            self::assertFalse(Hilos::$rt->hilosStatusMaintenance['current']->available);
            self::assertTrue(Hilos::$rt->hilosStatusMaintenance['current']->active);
            $this->runtime->sourceUnavailable = false;
            $this->runtime->phase($phase, intdiv($this->now, 1000) + 50, 'other-node', 'other-operation');
            $this->settle($this->now + 60000);
            self::assertSame($id, Hilos::$rt->hilosStatusMaintenance['current']->periodId);
            self::assertTrue(Hilos::$rt->hilosStatusMaintenance['current']->available);
            self::assertSame([], $this->mailFrames());
        } finally {
            Hilos::$db = $database;
            Hilos::$setting = $settings;
        }
        self::assertSame([], $this->rows());
    }

    public function testTransitIoRefusalCannotPreventHeartbeatOrReopening(): void
    {
        $this->settle($this->now - 1000);
        FsPath::write($this->transitPath(), 'A file obstructs the private directory.');
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $this->settle($this->now);
        self::assertTrue(Hilos::$rt->hilosStatusMaintenance['current']->active);
        $before = $this->heartbeatAt();
        $this->settle($this->now + 40000);
        self::assertGreaterThan($before, $this->heartbeatAt());
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle($this->now + 80000);
        self::assertSame(ProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
        self::assertFalse(Hilos::$rt->hilosStatusMaintenance['current']->active);
        self::assertGreaterThan($before, $this->heartbeatAt());
        self::assertSame([], $this->rows());
    }

    public function testATornAnnouncementPairDoesNotClassifyAColdFreeze(): void
    {
        $work = new StatusPublicWork(str_repeat('c', 32), 'Unproven announcement', null, ['service'], $this->now, $this->now + 10000);
        $this->publish(new StatusPublicSnapshot('coherent-before-tear', $this->now - 1000, 30, 120, [
            new StatusPublicComponent('service', 'Service', null, StatusComponentState::OPERATIONAL),
        ], [], [], [$work]));
        FsPath::write(Hilos::$fs->status['heartbeat.json']->getPath(), json_encode([
            'schemaVersion' => 2, 'revision' => 'different', 'aliveAt' => $this->now,
        ], JSON_THROW_ON_ERROR));
        $this->releaseOwner();
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $this->startOwner();
        $this->settle($this->now);
        self::assertFalse(Hilos::$rt->hilosStatusMaintenance['current']->announcementKnown);
        self::assertNull(Hilos::$rt->hilosStatusMaintenance['current']->workPublicId);
        self::assertSame('Maintenance', Hilos::$rt->hilosStatusMaintenance['current']->title);
    }

    public function testAConflictingPublicPeriodIdNeverOverwritesOrConsumesTheTransit(): void
    {
        $original = StatusMaintenanceObservation::start($this->now - 3000, $this->now - 2000, false, null)->complete($this->now - 1000, true);
        $period = $original->period;
        Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusMaintenancePeriods->actions->import(
            $period->id, null, null, false, $period->title, [], $period->startedAt, $period->lastObservedAt,
            $period->endedAt, $period->completionObservedAt,
        ));
        $before = $this->rows();
        $conflict = new StatusMaintenanceObservation(new StatusPublicMaintenancePeriod(
            $period->id, null, false, 'Different fact', [], $this->now, $this->now, null, $this->now + 1000,
        ), null);
        $store = new StatusMaintenanceObservationStore();
        $store->save($conflict);
        $this->releaseOwner();
        $this->startOwner();
        $this->settle($this->now + 2000);
        self::assertSame($before, $this->rows());
        self::assertSame($conflict->toArray(), $store->load($period->id . '.json')->toArray());
    }

    public function testAProvenInactiveIntervalSeparatesFreezesWhileTheFirstImportWaits(): void
    {
        $this->settle($this->now - 1000);
        $database = Hilos::$db;
        Hilos::$db = null;
        try {
            $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
            $this->settle($this->now);
            $firstId = Hilos::$rt->hilosStatusMaintenance['current']->periodId;
            $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
            $this->settle($this->now + 1000);
            self::assertFalse(Hilos::$rt->hilosStatusMaintenance['current']->active);
            $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000) + 2);
            $this->settle($this->now + 2000);
            $secondId = Hilos::$rt->hilosStatusMaintenance['current']->periodId;
            self::assertNotSame($firstId, $secondId);
            self::assertTrue(Hilos::$rt->hilosStatusMaintenance['current']->active);
        } finally {
            Hilos::$db = $database;
        }
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle($this->now + 4000);
        self::assertCount(2, $this->rows());
        self::assertEqualsCanonicalizing([$firstId, $secondId], array_column($this->rows(), 'public_id'));
    }

    public function testUnavailableDatabaseCannotMakeAnEmptyOverlayKnownUnannounced(): void
    {
        $this->releaseOwner();
        $database = Hilos::$db;
        $settings = Hilos::$setting;
        Hilos::$db = null;
        Hilos::$setting = null;
        try {
            $this->startOwner();
            $this->settle($this->now - 1000);
            self::assertSame([], (new StatusPublicFiles())->restore()->scheduledWorks);
            $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
            $this->settle($this->now);
            self::assertTrue(Hilos::$rt->hilosStatusMaintenance['current']->active);
            self::assertFalse(Hilos::$rt->hilosStatusMaintenance['current']->announcementKnown);
        } finally {
            Hilos::$db = $database;
            Hilos::$setting = $settings;
        }
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle($this->now + 1000);
        self::assertCount(1, $this->rows());
        self::assertSame(0, (int)$this->rows()[0]['announcement_known']);
    }

    public function testMutationBarrierWaitsForRecoveredCompletionAndConsumption(): void
    {
        $work = Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks->actions->create(
            'Recovered work', null, $this->now - 2000, $this->now + 10000, $this->now - 3000, ['service'],
        ));
        $snapshot = StatusPublicWork::fromWork($work);
        $active = StatusMaintenanceObservation::start($this->now - 1000, $this->now - 500, true, $snapshot);
        $this->publish(new StatusPublicSnapshot('active-before-restart', $this->now - 500, 30, 120, [
            new StatusPublicComponent('service', 'Service', null, StatusComponentState::MAINTENANCE),
        ], [], [], [$snapshot], [$active->period]));
        $this->releaseOwner();
        $this->startOwner();
        $refusals = 0;
        $ready = false;
        for ($step = 0; $step < 10; ++$step) {
            try {
                $this->owner->reconcileMaintenanceBeforeWrite($this->now + 1000 + $step);
                $ready = true;
                break;
            } catch (ValidationException) {
                ++$refusals;
                self::assertSame([], $this->rows());
            }
        }
        self::assertTrue($ready);
        self::assertGreaterThan(0, $refusals);
        self::assertCount(1, $this->rows());
        self::assertSame($this->now - 1000, (int)Database::sql(
            'SELECT consumed_at FROM hilos_status_work WHERE id = ?', [$work->id],
        )->rows()[0]['consumed_at']);
    }

    public function testSavedPublicIdentityNeverConsumesReusedIntegerAfterRestore(): void
    {
        $work = Database::transaction(DatabaseNames::PRIMARY, fn() => Hilos::$db->statusWorks->actions->create(
            'Original', null, $this->now, $this->now + 10000, $this->now - 2000, ['service'],
        ));
        $this->settle($this->now - 1000);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $this->settle($this->now);
        $publicId = $work->publicId;
        $this->releaseOwner();
        Database::sql('UPDATE hilos_status_work SET public_id = ?, title = ? WHERE id = ?', [str_repeat('f', 32), 'Replacement', $work->id]);
        Hilos::$db = new StatusProductionDbContext();
        Hilos::$db->configure();
        $this->startOwner();
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle($this->now + 1000);
        self::assertCount(1, $this->rows());
        self::assertSame($publicId, $this->rows()[0]['work_public_id']);
        self::assertNull($this->rows()[0]['work_id']);
        self::assertNull(Database::sql('SELECT consumed_at FROM hilos_status_work WHERE id = ?', [$work->id])->rows()[0]['consumed_at']);
    }

    public function testCommitReplayAndRollbackRetainOnePeriodAndTheTransitUntilCommit(): void
    {
        $observation = StatusMaintenanceObservation::start($this->now - 1000, $this->now - 500, true, null)->complete($this->now, true);
        $store = new StatusMaintenanceObservationStore();
        $store->save($observation);
        $mirror = new StatusMaintenanceFailingMirror();
        SourceChangeBus::subscribe($mirror);
        $this->releaseOwner();
        $this->startOwner();
        self::assertSame([], $this->rows());
        self::assertSame($observation->toArray(), $store->load($observation->period->id . '.json')->toArray());
        $this->settle($this->now + 1000);
        self::assertCount(1, $this->rows());
        $persisted = $this->rows();
        $store->save($observation); // Crash after commit and before unlink.
        $this->releaseOwner();
        $this->startOwner();
        $this->settle($this->now + 2000);
        self::assertSame($persisted, $this->rows());
        self::assertSame([], $store->discover());
    }

    public function testActiveCoherentPairRepairsLostTransitButCompletedHistoryIsNotImported(): void
    {
        $active = StatusMaintenanceObservation::start($this->now - 1000, $this->now - 500, false, null);
        $completed = StatusMaintenanceObservation::start($this->now - 3000, $this->now - 2500, true, null)->complete($this->now - 2000, true);
        $snapshot = new StatusPublicSnapshot('recovery', $this->now - 100, 30, 120, [
            new StatusPublicComponent('service', 'Service', null, StatusComponentState::MAINTENANCE),
        ], [], [], [], [$active->period, $completed->period]);
        $this->publish($snapshot);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE, intdiv($this->now, 1000));
        $this->releaseOwner();
        $this->startOwner();
        $this->settle($this->now);
        self::assertSame($active->period->id, Hilos::$rt->hilosStatusMaintenance['current']->periodId);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->settle($this->now + 1000);
        self::assertCount(1, $this->rows());
        self::assertSame($active->period->id, $this->rows()[0]['public_id']);
        self::assertNull($this->rows()[0]['ended_at']);
    }

    public function testBoundedRecoveryRunsBeforeBoundedExpiryAndCorruptFileDoesNotStopIt(): void
    {
        $store = new StatusMaintenanceObservationStore();
        for ($index = 0; $index < 70; ++$index) {
            $store->save(StatusMaintenanceObservation::start($this->now - 2000, $this->now - 1000, false, null)->complete($this->now, false));
        }
        FsPath::write($this->transitPath() . '/' . str_repeat('a', 32) . '.json', '{broken');
        Database::transaction(DatabaseNames::PRIMARY, function (): void {
            for ($index = 0; $index < 35; ++$index) {
                Hilos::$db->statusWorks->actions->create('Overdue ' . $index, null,
                    $this->now - 2000, $this->now - 1000, $this->now - 3000);
            }
        });
        $this->releaseOwner();
        $this->startOwner();
        $previous = count($this->rows());
        self::assertLessThanOrEqual(32, $previous);
        for ($step = 0; $step < 100; ++$step) {
            $this->owner->tickAt($this->now + $step);
            $current = count($this->rows());
            self::assertLessThanOrEqual(32, $current - $previous);
            $previous = $current;
        }
        self::assertCount(70, $this->rows());
        self::assertSame(35, (int)Database::sql('SELECT COUNT(*) AS n FROM hilos_status_work WHERE expired_at IS NOT NULL')->rows()[0]['n']);
        self::assertNotNull((new StatusPublicFiles())->restore());
    }

    /** @return list<array<string, mixed>> Independent persisted maintenance facts */
    private function rows(): array
    {
        return Database::sql('SELECT * FROM hilos_status_maintenance_period ORDER BY id')->rows();
    }

    /** @return int Last coherent public heartbeat moment */
    private function heartbeatAt(): int
    {
        return json_decode(Hilos::$fs->status['heartbeat.json']->read(), true, flags: JSON_THROW_ON_ERROR)['aliveAt'];
    }

    /** @return list<string> Any forbidden mail signals from the actual owner */
    private function mailFrames(): array
    {
        $names = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (str_contains($frame->signalName->getName(), 'mail')) {
                $names[] = $frame->signalName->getName();
            }
        }
        return $names;
    }

    /** @param int $at Tick clock at the start of a finite owner build */
    private function settle(int $at): void
    {
        for ($step = 0; $step < 25; ++$step) {
            $this->owner->tickAt($at + $step);
        }
    }

    /** @param StatusPublicSnapshot $snapshot Coherent public recovery fixture */
    private function publish(StatusPublicSnapshot $snapshot): void
    {
        $files = new StatusPublicFiles();
        $files->begin($snapshot);
        for ($step = 0; $files->isPublishing() && $step < 100; ++$step) {
            $files->advance($snapshot->generatedAt);
        }
        self::assertFalse($files->isPublishing());
    }

    /** Mounts real Status chains only after the test environment has been declared. */
    private function mountEnvironment(): void
    {
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusMachineHilos::class);
        foreach (array_reverse(self::TABLES) as $table) {
            $this->stub($table, true);
        }
        foreach (self::TABLES as $table) {
            $this->stub($table, false);
        }
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new StatusProductionDbContext();
        Hilos::$db->configure();
        Hilos::$env = new EnvAccessor();
        Hilos::$setting = new SettingsAccessor(StatusSettingsCatalog::class);
        Hilos::$notify = null;
        Hilos::$sr = new SignalRouter();
        $this->volume = new StatusPublicVolume();
        $this->runtime = new StatusMaintenanceLifecycleRuntime();
        $this->runtime->mountFeatureRuntime([new StatusFeature()]);
        $this->runtime->configure();
        $this->runtime->bindStateCollectionNames();
        Hilos::$rt = $this->runtime;
        $this->now = intdiv(TimeHelper::nowMs(), 1000) * 1000 + 10000;
        $this->startOwner();
    }

    /** Claims and starts a fresh actual Status owner, preserving transit and the public pair. */
    private function startOwner(): void
    {
        $this->owner = new StatusAgent();
        OwnershipDeclaration::claimAll($this->owner);
        $this->ownerFrame = ExecutionContext::push(new ExecutionFrame(agentId: $this->owner->getId()));
        $this->owner->onStart();
    }

    /** Releases this test's owner as the worker does after stopping it. */
    private function releaseOwner(): void
    {
        if (isset($this->ownerFrame)) {
            ExecutionContext::pop($this->ownerFrame);
            unset($this->ownerFrame);
        }
        if (isset($this->owner)) {
            TruthSourceRegistry::unregisterAgent($this->owner->getId());
            RtTruthSourceRegistry::unregisterAgent($this->owner->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
        }
    }

    /** @return string Private transit path on this isolated Status volume */
    private function transitPath(): string
    {
        return Hilos::$fs->status->getPath() . '/maintenance-observations';
    }

    /**
     * @param string $table Fixture table name
     * @param bool $drop Whether to drop rather than create
     */
    private function stub(string $table, bool $drop): void
    {
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_'
            . $table . ($drop ? '_down.sql' : '.sql')));
    }
}

/** Local freeze facts only; no change to protected-mode persistence or peer protocol. */
final class StatusMaintenanceLifecycleRuntime extends RtContext
{
    public bool $sourceUnavailable = false;

    /**
     * @param string $name Runtime source name
     * @return mixed Declared source, or the intentionally missing unconditional row
     */
    public function __get(string $name): RtCollection|RtItem|null
    {
        if ($name === ProtectedModeRuntime::RT_ITEM && $this->sourceUnavailable) {
            return null;
        }
        return parent::__get($name);
    }

    /** No project-owned Status mounts. */
    public function configure(): void
    {
    }

    /**
     * @param string $phase Unconditional freeze phase
     * @param ?int $startedAt Existing protected-mode seconds boundary
     * @param ?string $nodeId Changed node identity must not split a continuous observation
     * @param ?string $operation Changed operation must not split a continuous observation
     */
    public function phase(string $phase, ?int $startedAt = null, ?string $nodeId = null, ?string $operation = null): void
    {
        $freeze = ProtectedModeRuntime::create();
        $freeze->phase = $phase;
        $freeze->startedAt = $startedAt;
        $freeze->initiatorNodeId = $nodeId;
        $freeze->operation = $operation;
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $freeze);
    }
}

/** One real import failure after its DB write, before commit. */
final class StatusMaintenanceFailingMirror implements SourceMirrorSubscriberInterface
{
    private bool $armed = true;

    /**
     * @param SourceChange $change Actual ORM mutation
     * @param SourceChangeProvenance $provenance Origin, unused
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->armed && $change->sourceKey === HilosDbContext::statusMaintenancePeriods) {
            $this->armed = false;
            throw new RuntimeException('Injected maintenance import failure.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\View\Collection\StatusIncidents;
use Hilos\Database\View\Collection\StatusIncidentUpdates;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\Exception\StatusException;
use Hilos\Status\StatusPublicFiles;
use Hilos\Status\StatusPublicOutage;
use Hilos\Status\StatusPublicSnapshot;
use Hilos\Status\StatusPublicSnapshotBuilder;
use Hilos\Status\StatusPublicUpdate;
use Hilos\Status\StatusSeverity;
use Hilos\Status\StatusSnapshot;
use Hilos\Status\StatusPublicHeartbeat;
use Hilos\Status\StatusUpdateKind;
use Hilos\Status\StatusUpdateText;
use Hilos\Testing\IntegrationTest;

/** Real bounded SQL, rollback-owned fixtures and publication on an actual temporary volume. */
#[IntegrationTest]
final class StatusPublicFilesIntegrationTest extends StatusPublicFilesStorageTestCase
{
    public function testPublishedIntersectionAndIdPagesExcludeEveryHiddenHistory(): void
    {
        $this->startMachine();
        $now = 1800000000000;
        $since = $now - 90 * 86400000;
        $open = $this->published('old-open', 1000, 2000);
        $this->published('old-ended', 1000, 2000, $since - 1);
        $edge = $this->published('edge', $since - 1000, $since - 500, $since);
        $recent = $this->published('recent', $now - 1000, $now - 500, $now);
        $short = Hilos::$db->statusIncidents->actions->candidate('short', 'Short', StatusSeverity::MAJOR_OUTAGE, $now, $now + 1000);
        $short->actions->resolve($now + 1);
        $suppressed = Hilos::$db->statusIncidents->actions->candidate(
            'suppressed', 'Suppressed', StatusSeverity::MAJOR_OUTAGE, $now, $now + 1000,
        );
        $suppressed->actions->suppress($now);
        $suppressed->actions->resolve($now + 1);
        $checking = Hilos::$db->statusIncidents->actions->candidate('service', 'Service', StatusSeverity::MAJOR_OUTAGE, $now, $now + 1000);
        $checking->actions->suppress($now);
        Hilos::$rt->hilosStatusComponents->actions->ensure('service', 2);
        Hilos::$rt->hilosStatusComponents['service']->actions->update(2, 0, $now);

        $ids = [];
        $afterId = null;
        do {
            $page = Hilos::$db->statusIncidents->publishedIntersecting($since, $afterId, 1);
            self::assertLessThanOrEqual(1, count($page));
            foreach ($page as $incident) {
                $ids[] = $afterId = $incident->id;
            }
        } while (count($page) !== 0);
        self::assertSame([$open->id, $edge->id, $recent->id], $ids);
        self::assertCount(0, Hilos::$db->statusIncidents->publishedIntersecting($since, null, 0));
        self::assertCount(0, Hilos::$db->statusIncidents->publishedIntersecting($since, PHP_INT_MAX, 1));
        self::assertSame($ids, array_map(static fn(array $row): int => (int)$row['id'], Database::sql(
            'SELECT id FROM hilos_status_incident WHERE published_at IS NOT NULL AND (resolved_at IS NULL OR resolved_at >= ?) ORDER BY id',
            [$since],
        )->rows()));

        $builder = new StatusPublicSnapshotBuilder();
        $builder->begin(StatusSnapshot::read(), 'window', $now, 30, 120);
        $snapshot = $this->finish($builder);
        self::assertSame(['recent', 'edge', 'old-open'], array_column($snapshot->toArray()['outages'], 'componentKey'));
        self::assertSame([['componentKey' => 'service', 'detectedAt' => $now]], $snapshot->toArray()['candidates']);
        self::assertSame('checking', $snapshot->components[0]->state->value);
        self::assertSame([], $snapshot->outages[0]->updates);
        self::assertNull($snapshot->outages[2]->resolvedAt);
        $this->assertPublishedSnapshot($snapshot);
    }

    public function testLargeHistoryAndTimelineUseActualBoundedQueriesWithStableOrder(): void
    {
        $this->startMachine();
        $now = 1800000000000;
        $ids = [];
        for ($index = 0; $index < StatusIncidents::MAX_PORTION_ROWS + 3; ++$index) {
            $ids[] = $this->published('history-' . $index, $now - 1000 + $index % 3, $now)->id;
        }
        $parentId = $ids[0];
        for ($index = 0; $index < StatusIncidentUpdates::MAX_PORTION_ROWS * 2 + 7; ++$index) {
            Hilos::$db->statusIncidentUpdates->actions->append(
                $parentId, StatusUpdateKind::TEAM, StatusSeverity::MAJOR_OUTAGE,
                new StatusUpdateText('Published team text ' . $index), $now + $index % 5, 'private-request-' . $index,
            );
        }
        $builder = new StatusPublicSnapshotBuilder();
        $builder->begin(StatusSnapshot::read(), 'large', $now, 30, 120);
        $lastQuery = $this->lastProfileId();
        $historyQueries = [];
        $timelineQueries = [];
        for ($steps = 0; !$builder->isReady() && $steps < 200; ++$steps) {
            $builder->advance();
            $queries = $this->sourceQueriesSince($lastQuery);
            self::assertLessThanOrEqual(2, count($queries), 'One portion, with at most its bounded timeline anchor');
            foreach ($queries as $query) {
                self::assertMatchesRegularExpression('/ LIMIT (?:1|32|64)(?: OFFSET 0)?$/i', $query);
                if (str_contains($query, '`hilos_status_incident_update`')) {
                    $timelineQueries[] = $query;
                } else {
                    $historyQueries[] = $query;
                }
            }
        }
        self::assertTrue($builder->isReady());
        self::assertFalse($builder->isBuilding());
        self::assertCount(2, $historyQueries);
        foreach ($historyQueries as $query) {
            self::assertStringContainsString('`published_at` IS NOT NULL', $query);
            self::assertStringContainsString('`id` >', $query);
            self::assertStringContainsString('LIMIT 32', $query);
        }
        $largePortions = array_filter($timelineQueries, static fn(string $query): bool => str_contains($query, 'LIMIT 64'));
        // Three for the large timeline and one empty portion for each other incident.
        self::assertCount(count($ids) + 2, $largePortions);
        $snapshot = $builder->getSnapshot();
        self::assertSame($snapshot, $builder->getSnapshot());
        $expectedIds = array_map(static fn(array $row): int => (int)$row['id'], Database::sql(
            'SELECT id FROM hilos_status_incident ORDER BY detected_at DESC, id DESC',
        )->rows());
        self::assertSame(array_map(
            static fn(int $id): string => StatusPublicOutage::fromIncident(Hilos::$db->statusIncidents[$id])->id, $expectedIds,
        ), array_column($snapshot->toArray()['outages'], 'id'));
        $timeline = null;
        foreach ($snapshot->outages as $outage) {
            if ($outage->componentKey === 'history-0') {
                $timeline = array_map(static fn(StatusPublicUpdate $update): array => $update->toArray(), $outage->updates);
            }
        }
        $expected = Database::sql(
            'SELECT id, at, text FROM hilos_status_incident_update WHERE incident_id = ? ORDER BY at DESC, id DESC', [$parentId],
        )->rows();
        self::assertSame(array_column($expected, 'text'), array_column($timeline, 'text'));
        self::assertSame(array_map(static fn(array $row): int => (int)$row['at'], $expected), array_column($timeline, 'at'));
        self::assertCount(count($expected), array_unique(array_column($timeline, 'text')));
        self::assertStringNotContainsString('requestId', json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private-request', json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame($snapshot->toArray(), json_decode(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->assertPublishedSnapshot($snapshot);

        self::assertCount(StatusIncidents::MAX_PORTION_ROWS, Hilos::$db->statusIncidents->publishedIntersecting(0, null, PHP_INT_MAX));
        self::assertCount(StatusIncidentUpdates::MAX_PORTION_ROWS, Hilos::$db->statusIncidentUpdates->timelinePortion($parentId, null, PHP_INT_MAX));
        self::assertCount(0, Hilos::$db->statusIncidentUpdates->timelinePortion($parentId, null, -1));
        self::assertCount(0, Hilos::$db->statusIncidentUpdates->timelinePortion($ids[1], (int)$expected[0]['id'], 1));
        self::assertCount(0, Hilos::$db->statusIncidentUpdates->timelinePortion($parentId, PHP_INT_MAX, 1));
    }

    public function testResetDropsPartialTimelineAndFreezeDoesNoSourceQuery(): void
    {
        $this->startMachine();
        $incident = $this->published('service', 1000, 2000);
        for ($index = 0; $index < StatusIncidentUpdates::MAX_PORTION_ROWS + 1; ++$index) {
            Hilos::$db->statusIncidentUpdates->actions->append(
                $incident->id, StatusUpdateKind::TEAM, StatusSeverity::MAJOR_OUTAGE, new StatusUpdateText('Team ' . $index), 2000,
            );
        }
        $builder = new StatusPublicSnapshotBuilder();
        $builder->begin(StatusSnapshot::read(), 'discarded', 10000, 30, 120);
        $builder->advance();
        $builder->advance();
        self::assertFalse($builder->isReady());
        try {
            $builder->getSnapshot();
            self::fail('An incomplete timeline cannot become a public generation');
        } catch (StatusException) {
            self::assertTrue($builder->isBuilding());
        }
        Hilos::$db->statusIncidentUpdates->actions->append(
            $incident->id, StatusUpdateKind::RESOLVED, StatusSeverity::MAJOR_OUTAGE,
            new StatusUpdateText('PRIVATE exception node=hidden pid=42 IP=127.0.0.1'), 9000,
        );
        $incident->actions->resolve(9000);
        $builder->reset();
        $builder->begin(StatusSnapshot::read(), 'fresh', 10000, 30, 120);
        $snapshot = $this->finish($builder);
        self::assertSame('fresh', $snapshot->revision);
        self::assertCount(StatusIncidentUpdates::MAX_PORTION_ROWS + 2, $snapshot->outages[0]->updates);
        self::assertSame(['at' => 9000, 'source' => 'automatic', 'text' => 'Resolved.'], $snapshot->outages[0]->updates[0]->toArray());
        self::assertSame(9000, $snapshot->outages[0]->resolvedAt);

        $builder->begin(StatusSnapshot::read(), 'frozen-partial', 10000, 30, 120);
        $builder->advance();
        $this->machineRuntime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $lastQuery = $this->lastProfileId();
        $builder->advance();
        self::assertSame([], $this->sourceQueriesSince($lastQuery));
        self::assertFalse($builder->isBuilding());
        self::assertFalse($builder->isReady());
        self::assertSame($snapshot->toArray()['outages'], $snapshot->withCurrent(StatusSnapshot::read(), 'maintenance', 10001)->toArray()['outages']);
    }

    public function testOwnerExpiresHistoryWithoutNewReportsAndKeepsHeartbeatIndependentOfTheMachine(): void
    {
        $owner = $this->startMachine();
        $now = 1800000000000;
        $this->published('old-history', 1000, 2000, $now - 90 * 86400000 + 1000);
        $this->freshReport($owner, $now, [true, true]);
        for ($step = 0; $step < 8; ++$step) {
            $owner->tickAt($now + $step);
        }
        $first = StatusPublicSnapshot::fromJson(Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read());
        self::assertCount(1, $first->outages);
        $rows = $this->storedIncidents();
        $lastQuery = $this->lastProfileId();
        $owner->tickAt($now + 500);
        self::assertSame([], $this->sourceQueriesSince($lastQuery), 'An idle tick does not rebuild or reread history');
        for ($step = 0; $step < 8; ++$step) {
            $owner->tickAt($now + 1001 + $step);
        }
        $expired = StatusPublicSnapshot::fromJson(Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read());
        self::assertSame([], $expired->outages);
        self::assertNotSame($first->revision, $expired->revision);
        self::assertSame($rows, $this->storedIncidents(), 'Archive expiry changes no durable history');
        $lastQuery = $this->lastProfileId();
        $owner->tickAt($now + 32000);
        self::assertSame([], $this->sourceQueriesSince($lastQuery), 'Heartbeat-only work never reads history');
        $heartbeat = StatusPublicHeartbeat::fromJson(Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->read());
        self::assertSame($expired->revision, $heartbeat->revision);
        self::assertSame($now + 32000, $heartbeat->aliveAt);
    }

    public function testV2BuildKeepsActiveAnnouncementsAndRetainsPeriodsByCompletionKnowledge(): void
    {
        $this->startMachine();
        $now = 1800000000000;
        $since = $now - 90 * 86400000;
        $active = Hilos::$db->statusWorks->actions->create('Active past schedule', null, 1000, 2000, 500, ['service']);
        $active->actions->consume(1000);
        $next = Hilos::$db->statusWorks->actions->create('Next', 'Public description', $now + 2000, $now + 3000, 500, ['service']);
        $during = Hilos::$db->statusWorks->actions->create('Window has started', null, $now - 1000, $now + 1000, 500, ['service']);
        $canceled = Hilos::$db->statusWorks->actions->create('Canceled', null, $now + 4000, $now + 5000, 500);
        $canceled->actions->cancel(600);
        $consumed = Hilos::$db->statusWorks->actions->create('Consumed without active period', null, $now + 6000, $now + 7000, 500);
        $consumed->actions->consume($now + 6000);
        $expired = Hilos::$db->statusWorks->actions->create('Expired', null, $now + 8000, $now + 9000, 500);
        $expired->actions->expire($now + 9000);
        $open = Hilos::$db->statusMaintenancePeriods->actions->create($active->id, $active->publicId, true, 'Observed active', ['service'], 1000);
        $open->actions->reconcile(2000, null, null);
        $observed = Hilos::$db->statusMaintenancePeriods->actions->create(null, null, false, 'Completion after a gap', ['service'], 1000);
        $observed->actions->reconcile(2000, null, $since);
        $exact = Hilos::$db->statusMaintenancePeriods->actions->create(null, null, true, 'Exact completion', ['service'], 1000);
        $exact->actions->reconcile(2000, $since, $since);
        $old = Hilos::$db->statusMaintenancePeriods->actions->create(null, null, true, 'Old', ['service'], 1000);
        $old->actions->reconcile(2000, null, $since - 1);
        $retained = Hilos::$db->statusMaintenancePeriods->intersecting($since, null, 32);
        self::assertSame([$open->id, $observed->id, $exact->id], array_keys(iterator_to_array($retained)));

        $builder = new StatusPublicSnapshotBuilder();
        $builder->begin(StatusSnapshot::read(), 'maintenance-v2', $now, 30, 120);
        $snapshot = $this->finish($builder);
        self::assertSame([$active->publicId, $during->publicId, $next->publicId], array_column($snapshot->toArray()['scheduledWorks'], 'id'));
        self::assertSame([$open->publicId, $observed->publicId, $exact->publicId], array_column($snapshot->toArray()['maintenancePeriods'], 'id'));
        self::assertSame($active->publicId, $snapshot->maintenancePeriods[0]->workId);
        self::assertSame(['service'], $snapshot->scheduledWorks[0]->componentKeys);
        self::assertSame(['service'], $snapshot->maintenancePeriods[0]->componentKeys);
        self::assertNull($snapshot->maintenancePeriods[1]->endedAt);
        self::assertSame($since, $snapshot->maintenancePeriods[1]->completionObservedAt);
        self::assertSame($now + 1, $builder->getNextHistoryExpiryAt());
        $this->assertPublishedSnapshot($snapshot);
        foreach ($snapshot->toArray()['scheduledWorks'] as $work) {
            self::assertSame(['id', 'title', 'description', 'componentKeys', 'startsAt', 'endsAt'], array_keys($work));
        }
        foreach ($snapshot->toArray()['maintenancePeriods'] as $period) {
            self::assertSame(['id', 'workId', 'announcementKnown', 'title', 'componentKeys', 'startedAt',
                'lastObservedAt', 'endedAt', 'completionObservedAt'], array_keys($period));
        }
    }

    public function testLargeV2BuildAdvancesBoundedSelectionsWithoutHydratingTheSourceSet(): void
    {
        $this->startMachine();
        $now = 1800000000000;
        $workIds = [];
        $periodIds = [];
        for ($index = 0; $index < 35; ++$index) {
            $workIds[] = Hilos::$db->statusWorks->actions->create('Work ' . $index, null,
                $now + 1000 + $index * 2000, $now + 2000 + $index * 2000, 1000, ['service'])->publicId;
            $periodIds[] = Hilos::$db->statusMaintenancePeriods->actions->create(null, null, false,
                'Period ' . $index, ['retired-component'], 1000 + $index)->publicId;
        }
        $builder = new StatusPublicSnapshotBuilder();
        $builder->begin(StatusSnapshot::read(), 'large-v2', $now, 30, 120);
        $previousWorks = [];
        $previousPeriods = [];
        for ($steps = 0; !$builder->isReady() && $steps < 200; ++$steps) {
            $before = $this->lastProfileId();
            $builder->advance();
            $queries = [];
            foreach (Database::sql('SHOW PROFILES')->rows() as $profile) {
                if ((int)$profile['Query_ID'] > $before
                    && preg_match('/^SELECT .*FROM (?:`[^`]+`\.)?`hilos_status_(?:work|maintenance_period)`/s', $profile['Query'])) {
                    $queries[] = $profile['Query'];
                }
            }
            self::assertLessThanOrEqual(1, count($queries));
            foreach ($queries as $query) {
                self::assertStringContainsString('`id` >', $query);
                self::assertMatchesRegularExpression('/ LIMIT 1(?: OFFSET 0)?$/', $query);
                if (str_contains($query, '`hilos_status_maintenance_period`')) {
                    $previousPeriods[] = $query;
                } else {
                    $previousWorks[] = $query;
                }
            }
        }
        self::assertTrue($builder->isReady());
        self::assertCount(36, $previousWorks);
        self::assertCount(36, $previousPeriods);
        self::assertSame($workIds, array_column($builder->getSnapshot()->toArray()['scheduledWorks'], 'id'));
        self::assertSame($periodIds, array_column($builder->getSnapshot()->toArray()['maintenancePeriods'], 'id'));
        $this->assertPublishedSnapshot($builder->getSnapshot());
    }

    /** @param StatusPublicSnapshot $snapshot Projection built from actual database portions */
    private function assertPublishedSnapshot(StatusPublicSnapshot $snapshot): void
    {
        $path = sys_get_temp_dir() . '/hilos-status-published-' . uniqid('', true);
        $previousFs = Hilos::$fs;
        $previousEnv = Hilos::$env;
        $lastQuery = $this->lastProfileId();
        Hilos::$env = new EnvAccessor();
        Hilos::$fs = new class($path) extends FsContext {
            /** @param string $path Isolated real volume */
            public function __construct(private readonly string $path)
            {
            }

            /** Registers the public volume through the production named-directory API. */
            public function configure(): void
            {
                $this->registerDirectory(self::STATUS, $this->path, DirectoryScope::CLUSTER);
            }
        };
        Hilos::$fs->configure();
        try {
            Hilos::$fs->status->ensureDirectory();
            FsPath::write($path . '/' . ClusterDirectoryMarker::FILE_NAME, 'cluster-marker-must-survive');
            $files = new StatusPublicFiles();
            $files->begin($snapshot);
            $receipt = null;
            for ($step = 0; $step < 200 && $receipt === null; ++$step) {
                $receipt = $files->advance($snapshot->generatedAt);
            }
            self::assertNotNull($receipt);
            self::assertSame($snapshot->toArray(), json_decode(
                Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read(), true, flags: JSON_THROW_ON_ERROR,
            ));
            self::assertSame([
                'schemaVersion' => 2, 'revision' => $snapshot->revision, 'aliveAt' => $snapshot->generatedAt,
            ], json_decode(Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->read(), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame($snapshot->toArray(), (new StatusPublicFiles())->restore()->toArray());
            self::assertSame([], $this->sourceQueriesSince($lastQuery), 'Writing and restoring detached values must not query sources');
            self::assertSame(['heartbeat.json', 'status.json'], Hilos::$fs->status->entries());
            self::assertSame('cluster-marker-must-survive', FsPath::read($path . '/' . ClusterDirectoryMarker::FILE_NAME));
        } finally {
            if (is_dir($path)) {
                foreach (FsPath::entries($path) as $entry) {
                    FsPath::delete($path . '/' . $entry);
                }
                FsPath::removeDirectory($path);
            }
            Hilos::$fs = $previousFs;
            Hilos::$env = $previousEnv;
        }
    }

    /**
     * @param string $key Public component key, unique among open fixture incidents
     * @param int $detectedAt Detection epoch milliseconds UTC
     * @param int $publishedAt Publication epoch milliseconds UTC
     * @param ?int $resolvedAt Optional resolution epoch milliseconds UTC
     * @return StatusIncident Row written through the existing owner's actions
     */
    private function published(string $key, int $detectedAt, int $publishedAt, ?int $resolvedAt = null): StatusIncident
    {
        $incident = Hilos::$db->statusIncidents->actions->candidate(
            $key, 'Public component', StatusSeverity::MAJOR_OUTAGE, $detectedAt, $publishedAt,
        );
        $incident->actions->publish($publishedAt);
        if ($resolvedAt !== null) {
            $incident->actions->resolve($resolvedAt);
        }
        return $incident;
    }

    /**
     * @param StatusPublicSnapshotBuilder $builder Active build
     * @return StatusPublicSnapshot Complete generation within a finite test budget
     */
    private function finish(StatusPublicSnapshotBuilder $builder): StatusPublicSnapshot
    {
        for ($steps = 0; !$builder->isReady() && $steps < 200; ++$steps) {
            $builder->advance();
        }
        self::assertTrue($builder->isReady());
        return $builder->getSnapshot();
    }

    /** @return int Last query actually executed on this test session */
    private function lastProfileId(): int
    {
        $profiles = Database::sql('SHOW PROFILES')->rows();
        return $profiles === [] ? 0 : (int)$profiles[array_key_last($profiles)]['Query_ID'];
    }

    /**
     * @param int $lastQuery Consumed query cursor, advanced even for non-source statements
     * @return list<string> Actual Status SELECT statements since the preceding call
     */
    private function sourceQueriesSince(int &$lastQuery): array
    {
        $queries = [];
        foreach (Database::sql('SHOW PROFILES')->rows() as $profile) {
            if ((int)$profile['Query_ID'] > $lastQuery) {
                if (preg_match('/^SELECT .*FROM (?:`[^`]+`\.)?`hilos_status_incident(?:_update)?`/s', $profile['Query']) === 1) {
                    $queries[] = $profile['Query'];
                }
                $lastQuery = (int)$profile['Query_ID'];
            }
        }
        return $queries;
    }
}

/** Session SQL profiling is enabled outside the rollback scope and dies with the inherited connection. */
abstract class StatusPublicFilesStorageTestCase extends StatusStorageIntegrationTestCase
{
    /** Uses MariaDB's executed-query log to verify limits, without a production query hook. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        Database::sqlRun('SET SESSION profiling_history_size = 100, profiling = 1');
    }
}

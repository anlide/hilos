<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\Exception\StatusException;
use Hilos\Status\StatusComponentState;
use Hilos\Status\StatusPublicCandidate;
use Hilos\Status\StatusPublicComponent;
use Hilos\Status\StatusPublicFiles;
use Hilos\Status\StatusPublicHeartbeat;
use Hilos\Status\StatusPublicOutage;
use Hilos\Status\StatusPublicSnapshot;
use Hilos\Status\StatusPublicSnapshotBuilder;
use Hilos\Status\StatusPublicUpdate;
use Hilos\Status\StatusSnapshot;
use Hilos\Tests\Unit\Status\StatusReadTestCase;
use Hilos\Utils\Helpers\TimeHelper;

/** Public v1, real atomic files and detached recovery without a daemon or database. */
final class StatusPublicFilesTest extends StatusReadTestCase
{
    private string $path;
    private ?FsContext $previousFs;
    private ?EnvAccessor $previousEnv;
    /** @var array<string, string|false> Environment restored after the case */
    private array $previousIntervals;

    /** Mounts a real isolated status volume and the normal environment accessor. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFs = Hilos::$fs;
        $this->previousEnv = Hilos::$env;
        $this->previousIntervals = [];
        foreach ([EnvConstants::STATUS_POLL_INTERVAL_SECONDS, EnvConstants::STATUS_STALE_AFTER_SECONDS] as $key) {
            $this->previousIntervals[$key->name] = getenv($key->name);
            putenv($key->name);
        }
        Hilos::$env = new EnvAccessor();
        $this->path = sys_get_temp_dir() . '/hilos-status-files-' . uniqid('', true);
        Hilos::$fs = new StatusPublicFilesFsContext($this->path);
        Hilos::$fs->configure();
        Hilos::$fs->status->ensureDirectory();
        FsPath::write($this->path . '/' . ClusterDirectoryMarker::FILE_NAME, '{"private":"cluster marker"}');
    }

    /** Removes fixtures and restores globals even after a publication refusal. */
    protected function tearDown(): void
    {
        try {
            $this->removeTree($this->path);
        } finally {
            Hilos::$fs = $this->previousFs;
            Hilos::$env = $this->previousEnv;
            foreach ($this->previousIntervals as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
            parent::tearDown();
        }
    }

    public function testLiteralV1KeepsCatalogOrderNullsAndOnlyCurrentChecking(): void
    {
        $this->observe('zeta', 0);
        $this->observe('alpha', 2);
        $candidate = $this->incident(17, 'zeta', suppressedAt: 1700000000005);
        $this->incident(18, 'alpha', active: false);
        $snapshot = (new StatusPublicSnapshot('opaque-revision', 1700000000100, 30, 120, [], [], []))
            ->withCurrent(StatusSnapshot::read(), 'opaque-revision', 1700000000100);
        self::assertSame([
            'schemaVersion' => 1,
            'revision' => 'opaque-revision',
            'generatedAt' => 1700000000100,
            'config' => ['pollIntervalSeconds' => 30, 'staleAfterSeconds' => 120],
            'components' => [
                ['componentKey' => 'zeta', 'name' => 'Zeta API', 'description' => 'Public endpoints', 'state' => 'checking'],
                ['componentKey' => 'alpha', 'name' => 'Alpha mail', 'description' => null, 'state' => 'operational'],
            ],
            'candidates' => [['componentKey' => 'zeta', 'detectedAt' => 1700000000000]],
            'outages' => [], 'scheduledWorks' => [], 'maintenancePeriods' => [],
        ], $snapshot->toArray());
        $candidate->detected_at = 1700000000999;
        self::assertSame(1700000000000, $snapshot->candidates[0]->detectedAt, 'No DB reference survives sanitization');
        self::assertSame($snapshot->toArray(), json_decode(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAutomaticFactsNeverCopyPrivateTextAndTeamTextStaysPlain(): void
    {
        $incident = $this->incident(42, 'zeta', 1700000000010);
        $incident->peak_severity = 'major_outage';
        $incident->severity = 'partial_outage';
        $private = 'node-private PID=912 IP=127.0.0.1 token=secret exception subscriber@example.org Europe/Warsaw';
        $this->update(1, 42, 1700000000020, 'published', $private);
        $this->update(2, 42, 1700000000030, 'resolved', $private);
        $this->update(3, 42, 1700000000040, 'future_private_fact', $private);
        $this->update(4, 42, 1700000000050, 'team', '<b>Investigating</b> & waiting.');
        $outage = StatusPublicOutage::fromIncident(Hilos::$db->statusIncidents[42])->withUpdates([
            StatusPublicUpdate::fromUpdate(Hilos::$db->statusIncidentUpdates[4]),
            StatusPublicUpdate::fromUpdate(Hilos::$db->statusIncidentUpdates[2]),
            StatusPublicUpdate::fromUpdate(Hilos::$db->statusIncidentUpdates[1]),
        ]);
        self::assertNull(StatusPublicUpdate::fromUpdate(Hilos::$db->statusIncidentUpdates[3]));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $outage->id);
        self::assertSame($outage->id, StatusPublicOutage::fromIncident(Hilos::$db->statusIncidents[42])->id);
        self::assertSame('major_outage', $outage->toArray()['severity']);
        self::assertSame([
            ['at' => 1700000000050, 'source' => 'team', 'text' => '<b>Investigating</b> & waiting.'],
            ['at' => 1700000000030, 'source' => 'automatic', 'text' => 'Resolved.'],
            ['at' => 1700000000020, 'source' => 'automatic', 'text' => 'Major outage.'],
        ], $outage->toArray()['updates']);
        self::assertNull($outage->toArray()['resolvedAt']);
        $encoded = json_encode($outage->toArray(), JSON_THROW_ON_ERROR);
        foreach (['node', 'PID', 'IP', 'agentType', 'deadline', 'publishAt', 'requestId', 'token', 'secret', 'exception', '@', 'Warsaw'] as $word) {
            self::assertStringNotContainsString($word, $encoded);
        }
        $incident->resolved_at = 1700000000999;
        self::assertNull($outage->resolvedAt);
    }

    public function testUnknownAndMaintenanceKeepKnownHistoryWithoutDatabaseOrSettings(): void
    {
        $this->incident(8, 'zeta', 1700000000010);
        $known = (new StatusPublicSnapshot('known', 1700000000100, 30, 120, [], [], []))
            ->withOutages([StatusPublicOutage::fromIncident(Hilos::$db->statusIncidents[8])]);
        $database = Hilos::$db;
        $settings = Hilos::$setting;
        $runtime = Hilos::$rt;
        Hilos::$db = null;
        Hilos::$setting = null;
        try {
            $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
            $maintenance = $known->withCurrent(StatusSnapshot::read(), 'maintenance', 1700000000200);
            self::assertSame(['maintenance', 'maintenance'], array_column($maintenance->toArray()['components'], 'state'));
            self::assertSame([], $maintenance->candidates);
            Hilos::$rt = null;
            $unknown = $maintenance->withCurrent(StatusSnapshot::read(), 'unknown', 1700000000300);
            self::assertSame(['unknown', 'unknown'], array_column($unknown->toArray()['components'], 'state'));
            self::assertSame($known->toArray()['outages'], $unknown->toArray()['outages']);
        } finally {
            Hilos::$db = $database;
            Hilos::$setting = $settings;
            Hilos::$rt = $runtime;
        }
    }

    public function testPartialAndResetGenerationsCannotBeObtainedOrAdvancedUnderFreeze(): void
    {
        $builder = new StatusPublicSnapshotBuilder();
        self::assertFalse($builder->isReady());
        self::assertFalse($builder->isBuilding());
        $builder->begin(StatusSnapshot::read(), 'first', 1700000000100, 30, 120);
        self::assertTrue($builder->isBuilding());
        self::assertFalse($builder->isReady());
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $database = Hilos::$db;
        Hilos::$db = null;
        try {
            $builder->advance();
            self::assertFalse($builder->isBuilding());
            self::assertFalse($builder->isReady());
        } finally {
            Hilos::$db = $database;
        }
        $builder->reset();
        $this->expectException(StatusException::class);
        $builder->getSnapshot();
    }

    public function testWholePairReceiptAndHeartbeatLeaveStatusBytesInodeAndGenerationAlone(): void
    {
        $snapshot = $this->publicSnapshot();
        $files = new StatusPublicFiles();
        self::assertNull($files->getSnapshot());
        self::assertNull($files->heartbeat(100000));
        self::assertNull($files->advance(100000));
        $receipt = $this->publish($files, $snapshot, 100000);
        self::assertSame(['schemaVersion' => 1, 'revision' => 'first', 'aliveAt' => 100000], $receipt->toArray());
        self::assertSame($snapshot, $files->getSnapshot());
        self::assertSame($snapshot->toArray(), $this->readStatus());
        self::assertSame($receipt->toArray(), $this->readHeartbeat());
        $statusBytes = Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read();
        self::assertTrue(link($this->path . '/status.json', $this->path . '/status-inode'));
        $inode = fileinode($this->path . '/status-inode');
        self::assertNull($files->heartbeat(129999));
        $heartbeat = $files->heartbeat(130000);
        self::assertSame(['schemaVersion' => 1, 'revision' => 'first', 'aliveAt' => 130000], $heartbeat->toArray());
        self::assertNull($files->heartbeat(130000));
        self::assertSame($statusBytes, Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read());
        clearstatcache();
        self::assertSame($inode, fileinode($this->path . '/status.json'));
        self::assertSame($snapshot, $files->getSnapshot());
        self::assertSame('{"private":"cluster marker"}', FsPath::read($this->path . '/' . ClusterDirectoryMarker::FILE_NAME));
    }

    public function testEmptyProjectionRetainsEveryRequiredListAndRecoversWithoutInventingComponents(): void
    {
        $snapshot = new StatusPublicSnapshot('empty', 0, 30, 120, [], [], []);
        $files = new StatusPublicFiles();
        $this->publish($files, $snapshot, 0);
        self::assertSame([
            'schemaVersion' => 1, 'revision' => 'empty', 'generatedAt' => 0,
            'config' => ['pollIntervalSeconds' => 30, 'staleAfterSeconds' => 120],
            'components' => [], 'candidates' => [], 'outages' => [], 'scheduledWorks' => [], 'maintenancePeriods' => [],
        ], $this->readStatus());
        self::assertSame($snapshot->toArray(), (new StatusPublicFiles())->restore()->toArray());
        self::assertSame(30000, $files->heartbeat(30000)->aliveAt);
    }

    public function testSmallStaleCadenceIsPositiveAndIndependentOfPolling(): void
    {
        putenv('STATUS_POLL_INTERVAL_SECONDS=999');
        putenv('STATUS_STALE_AFTER_SECONDS=1');
        $files = new StatusPublicFiles();
        $this->publish($files, new StatusPublicSnapshot('small', 1000, 999, 1, [], [], []), 1000);
        self::assertNull($files->heartbeat(1000));
        self::assertNull($files->heartbeat(1333));
        self::assertSame(1334, $files->heartbeat(1334)->aliveAt);
        self::assertNull($files->heartbeat(1667));
        self::assertSame(1668, $files->heartbeat(1668)->aliveAt);
        self::assertSame(['pollIntervalSeconds' => 999, 'staleAfterSeconds' => 1], $this->readStatus()['config']);
    }

    public function testInvalidEnvironmentNeverEnablesPublisher(): void
    {
        foreach (['STATUS_POLL_INTERVAL_SECONDS', 'STATUS_STALE_AFTER_SECONDS'] as $key) {
            foreach (['0', '-1', '1.5', 'wrong'] as $value) {
                putenv($key . '=' . $value);
                try {
                    new StatusPublicFiles();
                    self::fail($key . '=' . $value . ' must be rejected');
                } catch (StatusException) {
                    self::assertSame([], Hilos::$fs->status->entries());
                }
            }
            putenv($key);
        }
    }

    public function testLargeTimelineSerializesInBoundedPortionsAndCancelPreservesThePair(): void
    {
        $files = new StatusPublicFiles();
        $first = $this->publicSnapshot();
        $this->publish($files, $first);
        $statusBytes = Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read();
        $heartbeatBytes = Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->read();
        $updates = [];
        for ($index = 0; $index < 257; ++$index) {
            $updates[] = StatusPublicUpdate::fromArray(['at' => 10000 - $index, 'source' => 'team', 'text' => str_repeat('Ж', 5000)]);
        }
        $large = new StatusPublicSnapshot('large', 1000, 30, 120, $first->components, [], [$first->outages[0]->withUpdates($updates)]);
        $files->begin($large);
        self::assertNull($files->advance(1000));
        self::assertSame($statusBytes, Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read());
        self::assertSame($heartbeatBytes, Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->read());
        self::assertSame('first', $files->heartbeat(999999)->revision, 'Private staging keeps the preceding coherent pair alive');
        $temps = array_values(array_filter(Hilos::$fs->status->entries(), static fn(string $name): bool => str_ends_with($name, '.tmp')));
        self::assertCount(1, $temps);
        self::assertMatchesRegularExpression('/^\.status-[a-f0-9]{32}\.tmp$/D', $temps[0]);
        self::assertLessThanOrEqual(65536, filesize($this->path . '/' . $temps[0]));
        $files->cancel();
        self::assertFalse($files->isPublishing());
        self::assertSame($first, $files->getSnapshot());
        self::assertSame(['heartbeat.json', 'status.json'], Hilos::$fs->status->entries());
        self::assertSame($statusBytes, Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read());

        $files->begin($large);
        $previousSize = 0;
        $receipt = null;
        for ($steps = 0; $steps < 200 && $receipt === null; ++$steps) {
            $receipt = $files->advance(1001);
            if ($receipt === null) {
                self::assertSame($first->toArray(), $this->readStatus(), 'No partial public JSON');
                $temps = array_values(array_filter(Hilos::$fs->status->entries(), static fn(string $name): bool => str_ends_with($name, '.tmp')));
                clearstatcache();
                $size = filesize($this->path . '/' . $temps[0]);
                self::assertGreaterThan($previousSize, $size);
                self::assertLessThanOrEqual(65536, $size - $previousSize);
                $previousSize = $size;
            }
        }
        self::assertNotNull($receipt);
        self::assertGreaterThan(4, $steps);
        self::assertSame($large->toArray(), $this->readStatus());
        self::assertSame($large->toArray(), (new StatusPublicFiles())->restore()->toArray());
        self::assertSame(['heartbeat.json', 'status.json'], Hilos::$fs->status->entries());
    }

    public function testSmallUpdatesAlsoNeedMultiplePortionsAndInterruptedStagingRestartsCleanly(): void
    {
        $first = $this->publicSnapshot();
        $updates = [];
        for ($index = 0; $index < 257; ++$index) {
            $updates[] = StatusPublicUpdate::fromArray(['at' => 10000 - $index, 'source' => 'team', 'text' => 'Update ' . $index]);
        }
        $large = new StatusPublicSnapshot('portions', 1000, 30, 120, [], [], [$first->outages[0]->withUpdates($updates)]);
        $files = new StatusPublicFiles();
        $this->publish($files, $first);
        $files->begin($large);
        self::assertNull($files->advance(2000));
        $temps = array_values(array_filter(Hilos::$fs->status->entries(), static fn(string $name): bool => str_ends_with($name, '.tmp')));
        $prefix = Hilos::$fs->status[$temps[0]]->read();
        self::assertLessThanOrEqual(64, substr_count($prefix, '"source":"team"'));
        FsPath::write($this->path . '/' . $temps[0], '');
        try {
            $files->advance(2000);
            self::fail('An interrupted prefix cannot become a public file');
        } catch (StatusException) {
            self::assertSame('first', $this->readStatus()['revision']);
            self::assertSame(1000, $this->readHeartbeat()['aliveAt']);
        }
        $receipt = null;
        for ($step = 0; $step < 10 && $receipt === null; ++$step) {
            $receipt = $files->advance(3000);
        }
        self::assertGreaterThanOrEqual(5, $step, 'Even a small timeline is bounded by the fragment count');
        self::assertNotNull($receipt);
        self::assertSame($large->toArray(), $this->readStatus());
        self::assertSame(['heartbeat.json', 'status.json'], Hilos::$fs->status->entries());
    }

    public function testSerializationFailureCannotTouchHeartbeatAndCanBeReplaced(): void
    {
        $files = new StatusPublicFiles();
        $this->publish($files, $this->publicSnapshot());
        $before = $this->readHeartbeat();
        $broken = new StatusPublicSnapshot('broken', 2000, 30, 120, [
            new StatusPublicComponent('bad', "\xFF", null, StatusComponentState::UNKNOWN),
        ], [], []);
        $files->begin($broken);
        try {
            $files->advance(2000);
            self::fail('Invalid UTF-8 must refuse the whole status file');
        } catch (StatusException) {
            self::assertSame($before, $this->readHeartbeat());
            self::assertSame('first', $this->readStatus()['revision']);
            self::assertNull($files->heartbeat(999999));
        }
        $this->publish($files, $this->publicSnapshot('fixed'), 3000);
        self::assertSame('fixed', $this->readHeartbeat()['revision']);
        self::assertSame(['heartbeat.json', 'status.json'], Hilos::$fs->status->entries());
    }

    public function testStatusRenameFailureLeavesHeartbeatOldAndRetriesWholeGeneration(): void
    {
        $files = new StatusPublicFiles();
        $this->publish($files, $this->publicSnapshot());
        $before = $this->readHeartbeat();
        rename($this->path . '/status.json', $this->path . '/saved-status');
        mkdir($this->path . '/status.json');
        $files->begin($this->publicSnapshot('second'));
        try {
            $files->advance(2000);
            self::fail('A directory cannot be replaced with status JSON');
        } catch (StatusException) {
            self::assertSame($before, $this->readHeartbeat());
            self::assertSame('first', $files->getSnapshot()->revision);
            self::assertNull($files->heartbeat(999999));
        }
        rmdir($this->path . '/status.json');
        rename($this->path . '/saved-status', $this->path . '/status.json');
        self::assertSame('second', $files->advance(3000)->revision);
        self::assertSame('second', $this->readStatus()['revision']);
        self::assertSame(3000, $this->readHeartbeat()['aliveAt']);
    }

    public function testFailureBeforeCreatingStatusNeverPublishesHeartbeat(): void
    {
        $files = new StatusPublicFiles();
        $this->publish($files, $this->publicSnapshot());
        $before = $this->readHeartbeat();
        rename($this->path, $this->path . '-saved');
        FsPath::write($this->path, 'The volume path is temporarily unavailable');
        try {
            $files->begin($this->publicSnapshot('retry'));
            try {
                $files->advance(2000);
                self::fail('A regular file cannot hold the status directory');
            } catch (StatusException) {
                self::assertNull($files->heartbeat(999999));
                self::assertSame($before, json_decode(FsPath::read($this->path . '-saved/heartbeat.json'), true, flags: JSON_THROW_ON_ERROR));
            }
        } finally {
            FsPath::delete($this->path);
            rename($this->path . '-saved', $this->path);
        }
        self::assertSame('retry', $files->advance(3000)->revision);
        self::assertSame(3000, $this->readHeartbeat()['aliveAt']);
    }

    public function testHeartbeatRenameFailureExposesMismatchUntilRetryAndNeverAnOldFreshHeartbeat(): void
    {
        $files = new StatusPublicFiles();
        $first = $this->publicSnapshot();
        $this->publish($files, $first);
        rename($this->path . '/heartbeat.json', $this->path . '/saved-heartbeat');
        mkdir($this->path . '/heartbeat.json');
        $files->begin($this->publicSnapshot('second'));
        try {
            $files->advance(2000);
            self::fail('Heartbeat rename must fail after installing status');
        } catch (StatusException) {
            self::assertSame('second', $this->readStatus()['revision']);
            self::assertSame($first, $files->getSnapshot());
            self::assertTrue($files->isPublishing());
            self::assertNull($files->heartbeat(999999));
            self::assertNull((new StatusPublicFiles())->restore());
        }
        rmdir($this->path . '/heartbeat.json');
        rename($this->path . '/saved-heartbeat', $this->path . '/heartbeat.json');
        self::assertSame(['schemaVersion' => 1, 'revision' => 'first', 'aliveAt' => 1000], $this->readHeartbeat());
        self::assertNull((new StatusPublicFiles())->restore(), 'Mismatching revisions are never recovered');
        self::assertTrue(link($this->path . '/status.json', $this->path . '/installed-inode'));
        $inode = fileinode($this->path . '/installed-inode');
        self::assertSame(['schemaVersion' => 1, 'revision' => 'second', 'aliveAt' => 3000], $files->advance(3000)->toArray());
        clearstatcache();
        self::assertSame($inode, fileinode($this->path . '/status.json'), 'Retry only the matching heartbeat');
        self::assertSame($files->getSnapshot()->toArray(), (new StatusPublicFiles())->restore()->toArray());
    }

    public function testCancelAfterStatusInstallationKeepsKnownHistoryButBlocksOldHeartbeat(): void
    {
        $files = new StatusPublicFiles();
        $first = $this->publicSnapshot();
        $this->publish($files, $first);
        rename($this->path . '/heartbeat.json', $this->path . '/saved-heartbeat');
        mkdir($this->path . '/heartbeat.json');
        $files->begin($this->publicSnapshot('canceled'));
        try {
            $files->advance(2000);
            self::fail('Heartbeat destination is blocked');
        } catch (StatusException) {
            $files->cancel();
        }
        rmdir($this->path . '/heartbeat.json');
        rename($this->path . '/saved-heartbeat', $this->path . '/heartbeat.json');
        self::assertFalse($files->isPublishing());
        self::assertSame($first, $files->getSnapshot());
        self::assertNull($files->heartbeat(999999));
        self::assertNull((new StatusPublicFiles())->restore());
        $this->publish($files, $this->publicSnapshot('repaired'), 4000);
        self::assertSame('repaired', $this->readHeartbeat()['revision']);
    }

    public function testStrictRecoveryRejectsBadStatusShapesWithoutErasingKnownHistory(): void
    {
        $files = new StatusPublicFiles();
        $snapshot = $this->publicSnapshot();
        $this->publish($files, $snapshot);
        $json = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);
        $invalid = ['{', 'null', '[]', '{}'];
        foreach ([
            ['schemaVersion', 2], ['schemaVersion', '1'], ['revision', ''], ['revision', 7],
            ['generatedAt', -1], ['generatedAt', 1.5], ['generatedAt', '1000'],
            ['components', (object)[]], ['candidates', null], ['outages', (object)[]],
            ['config', []], ['scheduledWorks', (object)[]], ['scheduledWorks', [['id' => 'future']]],
            ['maintenancePeriods', null],
        ] as [$key, $value]) {
            $data = $snapshot->toArray();
            $data[$key] = $value;
            $invalid[] = json_encode($data, JSON_THROW_ON_ERROR);
        }
        foreach (array_keys($snapshot->toArray()) as $key) {
            $data = $snapshot->toArray();
            unset($data[$key]);
            $invalid[] = json_encode($data, JSON_THROW_ON_ERROR);
        }
        foreach ([
            ['config', null, 'staleAfterSeconds', 0], ['config', null, 'pollIntervalSeconds', false],
            ['components', 0, 'state', 'future'], ['components', 0, 'description', 5],
            ['components', 0, 'name', null], ['components', 0, 'componentKey', ''],
            ['candidates', 0, 'detectedAt', -1], ['candidates', 0, 'componentKey', 8],
            ['outages', 0, 'id', null], ['outages', 0, 'severity', 'checking'],
            ['outages', 0, 'resolvedAt', false], ['outages', 0, 'updates', (object)[]],
            ['outages', 0, 'publishedAt', null],
        ] as [$list, $index, $key, $value]) {
            $data = $snapshot->toArray();
            if ($index === null) {
                $data[$list][$key] = $value;
            } else {
                $data[$list][$index][$key] = $value;
            }
            $invalid[] = json_encode($data, JSON_THROW_ON_ERROR);
        }
        foreach ([['at', '1000'], ['source', 'private'], ['text', null]] as [$key, $value]) {
            $data = $snapshot->toArray();
            $data['outages'][0]['updates'][0][$key] = $value;
            $invalid[] = json_encode($data, JSON_THROW_ON_ERROR);
        }
        foreach (['components', 'candidates', 'outages'] as $list) {
            foreach (array_keys($snapshot->toArray()[$list][0]) as $key) {
                $data = $snapshot->toArray();
                unset($data[$list][0][$key]);
                $invalid[] = json_encode($data, JSON_THROW_ON_ERROR);
            }
        }
        $data = $snapshot->toArray();
        $data['nodeId'] = 'private';
        $invalid[] = json_encode($data, JSON_THROW_ON_ERROR);
        $data = $snapshot->toArray();
        $data['outages'][0]['updates'][0]['requestId'] = 'private';
        $invalid[] = json_encode($data, JSON_THROW_ON_ERROR);
        foreach ($invalid as $badJson) {
            FsPath::write($this->path . '/status.json', $badJson);
            self::assertNull((new StatusPublicFiles())->restore(), $badJson);
            self::assertSame($snapshot, $files->restore(), 'Known history must survive failed reads');
            self::assertNull($files->heartbeat(999999));
        }
        FsPath::delete($this->path . '/status.json');
        self::assertNull((new StatusPublicFiles())->restore());
        self::assertSame($snapshot, $files->restore());
        FsPath::write($this->path . '/status.json', $json);
        self::assertSame($snapshot->toArray(), $files->restore()->toArray());
    }

    public function testStrictHeartbeatRecoveryAndColdFreezeUseOnlyPublicFiles(): void
    {
        $snapshot = $this->publicSnapshot();
        $files = new StatusPublicFiles();
        $this->publish($files, $snapshot);
        foreach (['{', 'null', '[]', '{}',
            '{"schemaVersion":2,"revision":"first","aliveAt":1000}',
            '{"schemaVersion":1,"revision":"other","aliveAt":1000}',
            '{"schemaVersion":1,"revision":"","aliveAt":1000}',
            '{"schemaVersion":1,"revision":"first","aliveAt":"1000"}',
            '{"schemaVersion":1,"revision":"first","aliveAt":-1}',
            '{"schemaVersion":1,"revision":"first","aliveAt":1.5}',
            '{"schemaVersion":1,"revision":"first"}',
            '{"schemaVersion":1,"revision":"first","aliveAt":1000,"pid":42}',
        ] as $badJson) {
            FsPath::write($this->path . '/heartbeat.json', $badJson);
            self::assertNull((new StatusPublicFiles())->restore(), $badJson);
        }
        FsPath::delete($this->path . '/heartbeat.json');
        self::assertNull((new StatusPublicFiles())->restore());
        FsPath::write($this->path . '/heartbeat.json', '{"schemaVersion":1,"revision":"first","aliveAt":1000}');
        $database = Hilos::$db;
        $settings = Hilos::$setting;
        $runtime = Hilos::$rt;
        Hilos::$db = null;
        Hilos::$setting = null;
        try {
            $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
            $cold = new StatusPublicFiles();
            $restored = $cold->restore();
            self::assertSame($snapshot->toArray(), $restored->toArray());
            $maintenance = $restored->withCurrent(StatusSnapshot::read(), 'frozen', 2000);
            self::assertSame(['maintenance', 'maintenance'], array_column($maintenance->toArray()['components'], 'state'));
            $this->publish($cold, $maintenance, 2000);
            self::assertSame($snapshot->toArray()['outages'], $this->readStatus()['outages']);
            self::assertSame(32000, $cold->heartbeat(32000)->aliveAt);
            Hilos::$rt = null;
            $unknown = $cold->getSnapshot()->withCurrent(StatusSnapshot::read(), 'unknown', 33000);
            $this->publish($cold, $unknown, 33000);
            self::assertSame(['unknown', 'unknown'], array_column($this->readStatus()['components'], 'state'));
            self::assertSame($snapshot->toArray()['outages'], $this->readStatus()['outages']);
        } finally {
            Hilos::$db = $database;
            Hilos::$setting = $settings;
            Hilos::$rt = $runtime;
        }
    }

    public function testColdOwnerPublishesAndHeartbeatsInEveryFreezePhaseWithoutDatabaseOrSettings(): void
    {
        $snapshot = $this->publicSnapshot();
        $database = Hilos::$db;
        $settings = Hilos::$setting;
        putenv('STATUS_POLL_INTERVAL_SECONDS=7');
        putenv('STATUS_STALE_AFTER_SECONDS=1');
        Hilos::$env = new EnvAccessor();
        Hilos::$db = null;
        Hilos::$setting = null;
        try {
            foreach ([ProtectedModeRuntime::PHASE_ACTIVATING, ProtectedModeRuntime::PHASE_ACTIVE,
                ProtectedModeRuntime::PHASE_VERIFYING, ProtectedModeRuntime::PHASE_DEACTIVATING] as $phase) {
                $this->publish(new StatusPublicFiles(), $snapshot);
                $this->runtime->phase($phase);
                $this->drain();
                $this->owner->onStart();
                $first = $this->readStatus();
                $heartbeat = $this->readHeartbeat();
                self::assertSame(['maintenance', 'maintenance'], array_column($first['components'], 'state'), $phase);
                self::assertSame([], $first['candidates']);
                self::assertSame($snapshot->toArray()['outages'], $first['outages']);
                self::assertSame(['pollIntervalSeconds' => 7, 'staleAfterSeconds' => 1], $first['config']);
                self::assertSame($first['revision'], $heartbeat['revision']);
                $this->owner->tickAt($heartbeat['aliveAt'] + 334);
                self::assertSame($first, $this->readStatus(), 'Heartbeat must retain revision and generatedAt');
                self::assertSame($heartbeat['aliveAt'] + 334, $this->readHeartbeat()['aliveAt']);
                $this->owner->onStop();
                $hints = $this->drain();
                self::assertCount(2, $hints);
                foreach ($hints as $hint) {
                    self::assertSame(HilosSignalConstants::STATUS_FILES_CHANGED, $hint->signalName->getName());
                    self::assertSame(SignalTypeConstants::WS_ALL_CONNECTED, $hint->signalType->getType());
                    self::assertSame([], $hint->data->data->toArray());
                }
            }
        } finally {
            Hilos::$db = $database;
            Hilos::$setting = $settings;
        }
    }

    public function testColdOwnerRefusesATornPairAndUnknownRuntimeNeverInventsMaintenance(): void
    {
        $this->publish(new StatusPublicFiles(), $this->publicSnapshot());
        FsPath::write($this->path . '/heartbeat.json', '{"schemaVersion":1,"revision":"other","aliveAt":1000}');
        $database = Hilos::$db;
        $settings = Hilos::$setting;
        $runtime = Hilos::$rt;
        Hilos::$db = null;
        Hilos::$setting = null;
        Hilos::$rt = null;
        try {
            $this->owner->onStart();
            $first = $this->readStatus();
            self::assertSame(['unknown', 'unknown'], array_column($first['components'], 'state'));
            self::assertSame([], $first['outages']);
            self::assertSame([], $first['candidates']);
            $at = $this->readHeartbeat()['aliveAt'];
            $this->owner->tickAt($at + 30000);
            self::assertSame($first, $this->readStatus());
            self::assertSame($at + 30000, $this->readHeartbeat()['aliveAt']);
            $this->owner->onStop();
            FsPath::delete($this->path . '/status.json');
            FsPath::delete($this->path . '/heartbeat.json');
            Hilos::$rt = $runtime;
            $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
            $this->owner->onStart();
            self::assertSame(['maintenance', 'maintenance'], array_column($this->readStatus()['components'], 'state'));
            self::assertSame([], $this->readStatus()['outages']);
            $this->owner->onStop();
        } finally {
            Hilos::$db = $database;
            Hilos::$setting = $settings;
            Hilos::$rt = $runtime;
        }
    }

    public function testDetachedArchiveExpiresWithoutEventsAndLargeCopiesStayBounded(): void
    {
        $snapshot = $this->publicSnapshot();
        $now = TimeHelper::nowMs();
        $expired = StatusPublicOutage::fromArray([
            'id' => 'expired', 'componentKey' => 'alpha', 'severity' => 'major_outage',
            'detectedAt' => 1, 'publishedAt' => 2, 'resolvedAt' => $now - 90 * 86400000,
            'updates' => [],
        ]);
        $this->publish(new StatusPublicFiles(), $snapshot->withOutages([$expired, ...array_fill(0, 70, $snapshot->outages[0])]));
        $database = Hilos::$db;
        Hilos::$db = null;
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        try {
            $this->owner->onStart();
            self::assertSame('first', $this->readStatus()['revision'], 'A partial detached copy cannot replace the old file');
            $at = TimeHelper::nowMs();
            for ($step = 0; $step < 30; ++$step) {
                $this->owner->tickAt($at + $step);
            }
            self::assertCount(70, $this->readStatus()['outages']);
            self::assertNotContains('expired', array_column($this->readStatus()['outages'], 'id'));
            $this->owner->onStop();
        } finally {
            Hilos::$db = $database;
        }
    }

    /**
     * @param string $revision Public generation identity
     * @return StatusPublicSnapshot Complete fixture with every public row type
     */
    private function publicSnapshot(string $revision = 'first'): StatusPublicSnapshot
    {
        return new StatusPublicSnapshot($revision, 1000, 30, 120, [
            new StatusPublicComponent('zeta', 'Zeta API', 'Public endpoints', StatusComponentState::CHECKING),
            new StatusPublicComponent('alpha', 'Alpha mail', null, StatusComponentState::OPERATIONAL),
        ], [new StatusPublicCandidate('zeta', 500)], [StatusPublicOutage::fromArray([
            'id' => 'opaque-outage', 'componentKey' => 'alpha', 'severity' => 'major_outage',
            'detectedAt' => 200, 'publishedAt' => 300, 'resolvedAt' => null,
            'updates' => [['at' => 400, 'source' => 'team', 'text' => '<b>Published plain text</b> & waiting.']],
        ])]);
    }

    /**
     * @param StatusPublicFiles $files Publisher under test
     * @param StatusPublicSnapshot $snapshot New generation
     * @param int $nowMs Publication moment
     * @return StatusPublicHeartbeat Receipt produced within a finite test budget
     */
    private function publish(StatusPublicFiles $files, StatusPublicSnapshot $snapshot, int $nowMs = 1000): StatusPublicHeartbeat
    {
        $files->begin($snapshot);
        for ($step = 0; $step < 200; ++$step) {
            $receipt = $files->advance($nowMs);
            if ($receipt !== null) {
                self::assertFalse($files->isPublishing());
                return $receipt;
            }
        }
        self::fail('Public generation did not finish');
    }

    /** @return array<string, mixed> Public status decoded as a reader sees it */
    private function readStatus(): array
    {
        return json_decode(Hilos::$fs->status[StatusPublicFiles::STATUS_FILENAME]->read(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> Public heartbeat decoded as a reader sees it */
    private function readHeartbeat(): array
    {
        return json_decode(Hilos::$fs->status[StatusPublicFiles::HEARTBEAT_FILENAME]->read(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $path Temporary fixture path; never a production cluster volume
     */
    private function removeTree(string $path): void
    {
        if (is_dir($path)) {
            foreach (FsPath::entries($path) as $name) {
                $this->removeTree($path . '/' . $name);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}

/** Test-only registration of a real isolated cluster directory. */
final class StatusPublicFilesFsContext extends FsContext
{
    /** @param string $path Temporary public volume */
    public function __construct(private readonly string $path)
    {
    }

    /** Registers the reserved status directory with its production cluster scope. */
    public function configure(): void
    {
        $this->registerDirectory(self::STATUS, $this->path, DirectoryScope::CLUSTER);
    }
}

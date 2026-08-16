<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\Ship\BackupShipPlanner;
use Hilos\Backup\Ship\BackupShipStep;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Item\BackupHistory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the queue behind shipping.
 *
 * There is no persisted queue: the runtime index is it, so every case here is a question about
 * rows and files, and the answer is the single transfer to run next.
 */
final class BackupShipPlannerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-ship-' . getmypid() . '-' . uniqid();
        foreach (BackupScope::cases() as $scope) {
            mkdir($this->root . '/' . $scope->value, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (BackupScope::cases() as $scope) {
            foreach (glob($this->root . '/' . $scope->value . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->root . '/' . $scope->value);
        }
        rmdir($this->root);
    }

    public function testTheNewestBackupOwedACopyGoesFirst(): void
    {
        // On a narrow link a fresh restore point is worth more than an old one.
        $rows = [
            $this->row('old', createdAt: '2026-08-01T00:00:00+00:00'),
            $this->row('new', createdAt: '2026-08-16T00:00:00+00:00'),
            $this->row('middle', createdAt: '2026-08-10T00:00:00+00:00'),
        ];

        $plan = new BackupShipPlanner()->plan($rows, $this->root, [], false, 1_000_000.0);

        $this->assertNotNull($plan);
        $this->assertSame('new', $plan->backupId);
        $this->assertSame(BackupShipStep::PUSH_ARCHIVE, $plan->step);
        $this->assertSame('full', $plan->scope);
        // The stored name is <id>-<env>-<scope>, not the id alone: the row is addressed on disk
        // the same way rotation and the delete action address it.
        $this->assertSame($this->root . '/full/new-test-full.tar.gz', $plan->localPath);
    }

    public function testABackupAlreadyShippedIsNoLongerACandidate(): void
    {
        $rows = [
            $this->row('done', createdAt: '2026-08-16T00:00:00+00:00', shipOutcome: BackupShipOutcome::OK),
            $this->row('owed', createdAt: '2026-08-15T00:00:00+00:00'),
        ];

        $this->assertSame(
            'owed',
            new BackupShipPlanner()->plan($rows, $this->root, [], false, 1_000_000.0)?->backupId,
        );
    }

    public function testAFailedAttemptStaysInTheQueue(): void
    {
        // Retries are unbounded while the archive is here: a receiver that was down comes back.
        $rows = [$this->row('retried', shipOutcome: BackupShipOutcome::FAILED)];

        $this->assertSame(
            'retried',
            new BackupShipPlanner()->plan($rows, $this->root, [], false, 1_000_000.0)?->backupId,
        );
    }

    public function testErrorRowsAreNeverShipped(): void
    {
        // An error record has a sidecar and no archive: there is nothing to copy.
        $rows = [$this->row('failed-run', status: BackupStatus::ERROR, withArchive: false)];

        $this->assertNull(new BackupShipPlanner()->plan($rows, $this->root, [], false, 1_000_000.0));
    }

    public function testARowWhoseArchiveIsGoneIsSkipped(): void
    {
        // Rotation deleted the archive while the index row was still catching up: attempts stop
        // by themselves rather than retrying a file that no longer exists.
        $rows = [
            $this->row('rotated', createdAt: '2026-08-16T00:00:00+00:00', withArchive: false),
            $this->row('present', createdAt: '2026-08-15T00:00:00+00:00'),
        ];

        $this->assertSame(
            'present',
            new BackupShipPlanner()->plan($rows, $this->root, [], false, 1_000_000.0)?->backupId,
        );
    }

    public function testABackupTriedRecentlyWaitsAndTheNextOneGoesInstead(): void
    {
        $rows = [
            $this->row('just-tried', createdAt: '2026-08-16T00:00:00+00:00'),
            $this->row('older', createdAt: '2026-08-15T00:00:00+00:00'),
        ];
        $now = 1_000_000.0;
        $lastAttemptAt = ['just-tried' => $now - (BackupShipPlanner::RETRY_SECONDS - 1)];

        $this->assertSame(
            'older',
            new BackupShipPlanner()->plan($rows, $this->root, $lastAttemptAt, false, $now)?->backupId,
        );

        // Once the interval has elapsed the newest backup takes the link back.
        $elapsed = ['just-tried' => $now - BackupShipPlanner::RETRY_SECONDS];
        $this->assertSame(
            'just-tried',
            new BackupShipPlanner()->plan($rows, $this->root, $elapsed, false, $now)?->backupId,
        );
    }

    public function testTheSidecarFollowsTheArchiveOfTheSameBackup(): void
    {
        // The remote publish order mirrors the local one: an interrupted transfer can leave a
        // remote archive without a sidecar, never a sidecar without its archive.
        $planner = new BackupShipPlanner();
        $archive = $planner->plan([$this->row('paired')], $this->root, [], false, 1_000_000.0);
        $this->assertNotNull($archive);

        $sidecar = $planner->sidecarStep($archive);

        $this->assertSame(BackupShipStep::PUSH_SIDECAR, $sidecar->step);
        $this->assertSame('paired', $sidecar->backupId);
        $this->assertSame('full', $sidecar->scope);
        $this->assertSame($this->root . '/full/paired-test-full.json', $sidecar->localPath);
    }

    public function testARowNamingNoEnvironmentOrScopeIsNotShippable(): void
    {
        // The stored name is built from all three, so such a row cannot be addressed on disk at
        // all - it predates the fields or was hand written, and is not a backup owed a copy.
        $state = StateBackupHistory::fromRow([
            StateBackupHistory::id => 'nameless',
            StateBackupHistory::createdAt => '2026-08-16T00:00:00+00:00',
            StateBackupHistory::status => 'success',
        ]);
        $rows = [new BackupHistory($state)];

        $this->assertNull(new BackupShipPlanner()->plan($rows, $this->root, [], false, 1_000_000.0));
    }

    public function testAnIdleQueueWithNothingDeletedPlansNothing(): void
    {
        $this->assertNull(new BackupShipPlanner()->plan([], $this->root, [], false, 1_000_000.0));
    }

    public function testAMirrorRunsOnlyOnceTheQueueIsEmpty(): void
    {
        // A mirror deletes remotely; running it while pushes are outstanding would spend the link
        // removing files in the same pass that is trying to add them.
        $rows = [$this->row('owed')];

        $withWork = new BackupShipPlanner()->plan($rows, $this->root, [], true, 1_000_000.0);
        $this->assertSame(BackupShipStep::PUSH_ARCHIVE, $withWork?->step);

        $idle = new BackupShipPlanner()->plan([], $this->root, [], true, 1_000_000.0);
        $this->assertSame(BackupShipStep::MIRROR, $idle?->step);
        $this->assertNull($idle->backupId);
        $this->assertSame($this->root . '/full', $idle->localPath);
    }

    public function testTheMirrorPassWalksEveryScopeAndThenStops(): void
    {
        // One bool says "something was deleted"; the attempt map is what sequences the scopes and
        // ends the pass, so a dirty mirror cannot loop on the first scope forever.
        $planner = new BackupShipPlanner();
        $now = 1_000_000.0;
        $lastAttemptAt = [];

        $mirrored = [];
        while (($plan = $planner->plan([], $this->root, $lastAttemptAt, true, $now)) !== null) {
            $mirrored[] = $plan->scope;
            $lastAttemptAt[BackupShipPlanner::MIRROR_ATTEMPT_PREFIX . $plan->scope] = $now;
        }

        $this->assertSame(['full', 'schema-seed', 'schema-only'], $mirrored);
    }

    public function testASweepSlowerThanTheRetryIntervalStillEnds(): void
    {
        // The mark of a mirrored scope is read by presence, not by age: on a narrow link one pass
        // can outlast any interval, and an aged mark would make the first scope due again before
        // the last one is reached - a receiver re-stated forever, with mirrorDirty never clearing.
        $planner = new BackupShipPlanner();
        $now = 1_000_000.0;
        $lastAttemptAt = [];

        $mirrored = [];
        while (($plan = $planner->plan([], $this->root, $lastAttemptAt, true, $now)) !== null) {
            $mirrored[] = $plan->scope;
            $lastAttemptAt[BackupShipPlanner::MIRROR_ATTEMPT_PREFIX . $plan->scope] = $now;
            // Every scope takes longer to send than the interval a failing push is re-tried on.
            $now += BackupShipPlanner::RETRY_SECONDS * 2;
        }

        $this->assertSame(['full', 'schema-seed', 'schema-only'], $mirrored);
    }

    public function testAScopeWithNoLocalDirectoryIsNotMirrored(): void
    {
        // An empty source would ask rsync to delete the whole remote scope, which is a different
        // operation than mirroring what rotation removed.
        rmdir($this->root . '/full');

        $plan = new BackupShipPlanner()->plan([], $this->root, [], true, 1_000_000.0);

        $this->assertSame('schema-seed', $plan?->scope);

        mkdir($this->root . '/full');
    }

    /**
     * Builds one index row, and by default the archive on disk that makes it shippable.
     *
     * @param string $id Backup id, also the archive base name
     * @param string $createdAt ISO-8601 creation timestamp
     * @param BackupStatus $status Terminal status of the run
     * @param ?BackupShipOutcome $shipOutcome Recorded outcome of the last copy attempt
     * @param bool $withArchive Whether the archive file exists locally
     * @param BackupScope $scope Scope the backup belongs to
     * @return BackupHistory Index row for the planner
     */
    private function row(
        string $id,
        string $createdAt = '2026-08-16T00:00:00+00:00',
        BackupStatus $status = BackupStatus::SUCCESS,
        ?BackupShipOutcome $shipOutcome = null,
        bool $withArchive = true,
        BackupScope $scope = BackupScope::FULL,
    ): BackupHistory {
        if ($withArchive) {
            $base = BackupCreator::archiveBaseName($id, 'test', $scope);
            file_put_contents($this->root . '/' . $scope->value . '/' . $base . '.tar.gz', 'archive');
        }

        $state = StateBackupHistory::fromMetadata(new BackupMetadata(
            id: $id,
            createdAt: $createdAt,
            env: 'test',
            scope: $scope,
            connections: [],
            sizeBytes: 7,
            durationSeconds: 1,
            keep: false,
            status: $status,
            shippedAt: $shipOutcome === BackupShipOutcome::OK ? $createdAt : null,
            shipOutcome: $shipOutcome,
        ));

        return new BackupHistory($state);
    }
}

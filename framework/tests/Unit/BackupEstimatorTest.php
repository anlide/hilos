<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupEstimator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Item\BackupHistory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the duration a run about to start is promised.
 *
 * The estimator reads the backup index and nothing else, so every case here is a shape of history:
 * too little of it, too much of it, and rows that look usable but carry no figure. The two runs are
 * pinned separately because they are estimated differently - a create from the durations of its
 * scope, a restore from the speed of the recorded restores multiplied by the archive at hand.
 */
final class BackupEstimatorTest extends TestCase
{
    public function testACreateIsEstimatedByTheMedianDurationOfItsScope(): void
    {
        $rows = [
            $this->row(BackupScope::FULL, createdAt: '2026-08-01T00:00:00+00:00', durationSeconds: 100),
            $this->row(BackupScope::FULL, createdAt: '2026-08-02T00:00:00+00:00', durationSeconds: 300),
            $this->row(BackupScope::FULL, createdAt: '2026-08-03T00:00:00+00:00', durationSeconds: 200),
        ];

        $this->assertSame(200, BackupEstimator::createSeconds($rows, BackupScope::FULL));
    }

    public function testOnlyTheNewestFiveCreateRunsCount(): void
    {
        // Six runs, newest first by date: {60,50,40,30,20} are the five that count, median 40.
        $rows = [];
        foreach ([10, 20, 30, 40, 50, 60] as $index => $duration) {
            $rows[] = $this->row(
                BackupScope::FULL,
                createdAt: sprintf('2026-08-%02dT00:00:00+00:00', $index + 1),
                durationSeconds: $duration,
            );
        }

        $this->assertSame(40, BackupEstimator::createSeconds($rows, BackupScope::FULL));
    }

    public function testRunsOfAnotherScopeFailedRunsAndTimelessRunsAreNotEstimatedFrom(): void
    {
        $rows = [
            $this->row(BackupScope::SCHEMA_ONLY, createdAt: '2026-08-01T00:00:00+00:00', durationSeconds: 900),
            $this->row(
                BackupScope::FULL,
                createdAt: '2026-08-02T00:00:00+00:00',
                durationSeconds: 900,
                status: BackupStatus::ERROR,
            ),
            $this->row(BackupScope::FULL, createdAt: '2026-08-03T00:00:00+00:00', durationSeconds: 0),
            $this->row(BackupScope::FULL, createdAt: '2026-08-04T00:00:00+00:00', durationSeconds: 120),
        ];

        $this->assertSame(120, BackupEstimator::createSeconds($rows, BackupScope::FULL));
    }

    public function testACreateWithNoHistoryOfItsScopeHasNoEstimate(): void
    {
        $rows = [$this->row(BackupScope::SCHEMA_ONLY, createdAt: '2026-08-01T00:00:00+00:00', durationSeconds: 120)];

        $this->assertNull(BackupEstimator::createSeconds($rows, BackupScope::FULL));
        $this->assertNull(BackupEstimator::createSeconds([], BackupScope::FULL));
    }

    public function testARestoreIsEstimatedBySpeedTimesTheArchiveAtHand(): void
    {
        // 200 seconds over 100 bytes is 2 s/byte; a 250-byte archive is therefore 500 seconds.
        $rows = [
            $this->row(
                BackupScope::FULL,
                createdAt: '2026-08-01T00:00:00+00:00',
                sizeBytes: 100,
                restoredAt: '2026-08-05T00:00:00+00:00',
                restoreDurationSeconds: 200,
            ),
        ];

        $this->assertSame(500, BackupEstimator::restoreSeconds($rows, BackupScope::FULL, 250));
    }

    public function testTheRestoreSpeedIsTheMedianOfTheRecordedOnes(): void
    {
        // Speeds 1, 2 and 4 s/byte -> median 2, over a 50-byte archive -> 100 seconds.
        $rows = [
            $this->restoredRow('2026-08-05T00:00:00+00:00', sizeBytes: 100, restoreDurationSeconds: 100),
            $this->restoredRow('2026-08-06T00:00:00+00:00', sizeBytes: 100, restoreDurationSeconds: 200),
            $this->restoredRow('2026-08-07T00:00:00+00:00', sizeBytes: 100, restoreDurationSeconds: 400),
        ];

        $this->assertSame(100, BackupEstimator::restoreSeconds($rows, BackupScope::FULL, 50));
    }

    public function testOnlyTheNewestFiveRestoresCountAndTheyAreOrderedByWhenTheyRan(): void
    {
        // The restores run in the opposite order to the archives' age: the five newest restores are
        // {5,4,3,2,1} s/byte by their restoredAt, median 3, over a 10-byte archive -> 30 seconds.
        $rows = [];
        foreach ([6, 5, 4, 3, 2, 1] as $index => $seconds) {
            $rows[] = $this->restoredRow(
                sprintf('2026-08-%02dT00:00:00+00:00', $index + 1),
                sizeBytes: 1,
                restoreDurationSeconds: $seconds,
                createdAt: sprintf('2026-07-%02dT00:00:00+00:00', 30 - $index),
            );
        }

        $this->assertSame(30, BackupEstimator::restoreSeconds($rows, BackupScope::FULL, 10));
    }

    public function testArchivesNeverRestoredOrCarryingNoSizeAreNotEstimatedFrom(): void
    {
        $rows = [
            $this->row(BackupScope::FULL, createdAt: '2026-08-01T00:00:00+00:00', sizeBytes: 100),
            $this->restoredRow('2026-08-05T00:00:00+00:00', sizeBytes: 0, restoreDurationSeconds: 300),
            $this->restoredRow('2026-08-06T00:00:00+00:00', sizeBytes: 100, restoreDurationSeconds: 300),
        ];

        $this->assertSame(30, BackupEstimator::restoreSeconds($rows, BackupScope::FULL, 10));
    }

    public function testARestoreWithoutHistoryOrWithoutAnArchiveSizeHasNoEstimate(): void
    {
        $noHistory = [$this->row(BackupScope::FULL, createdAt: '2026-08-01T00:00:00+00:00', sizeBytes: 100)];
        $this->assertNull(BackupEstimator::restoreSeconds($noHistory, BackupScope::FULL, 100));

        $history = [$this->restoredRow('2026-08-05T00:00:00+00:00', sizeBytes: 100, restoreDurationSeconds: 300)];
        $this->assertNull(BackupEstimator::restoreSeconds($history, BackupScope::FULL, 0));
    }

    /**
     * An index row of an archive that has been restored once.
     *
     * @param string $restoredAt ISO-8601 instant the restore finished
     * @param int $sizeBytes Archive size in bytes
     * @param int $restoreDurationSeconds How long that restore took
     * @param string $createdAt ISO-8601 instant the archive itself was written
     * @return BackupHistory Index row carrying the restore
     */
    private function restoredRow(
        string $restoredAt,
        int $sizeBytes,
        int $restoreDurationSeconds,
        string $createdAt = '2026-08-01T00:00:00+00:00',
    ): BackupHistory {
        return $this->row(
            BackupScope::FULL,
            createdAt: $createdAt,
            sizeBytes: $sizeBytes,
            restoredAt: $restoredAt,
            restoreDurationSeconds: $restoreDurationSeconds,
        );
    }

    /**
     * One index row, built the way the scanner builds it - out of a sidecar.
     *
     * @param BackupScope $scope Scope the backup captured
     * @param string $createdAt ISO-8601 creation timestamp
     * @param int $sizeBytes Archive size in bytes
     * @param int $durationSeconds Wall-clock capture duration in seconds
     * @param BackupStatus $status Terminal outcome of the capture
     * @param ?string $restoredAt ISO-8601 instant the archive was last restored from
     * @param int $restoreDurationSeconds How long that restore took
     * @return BackupHistory Index row
     */
    private function row(
        BackupScope $scope,
        string $createdAt,
        int $sizeBytes = 0,
        int $durationSeconds = 0,
        BackupStatus $status = BackupStatus::SUCCESS,
        ?string $restoredAt = null,
        int $restoreDurationSeconds = 0,
    ): BackupHistory {
        $state = StateBackupHistory::fromMetadata(new BackupMetadata(
            id: $scope->value . '-' . $createdAt,
            createdAt: $createdAt,
            env: 'test',
            scope: $scope,
            connections: [],
            sizeBytes: $sizeBytes,
            durationSeconds: $durationSeconds,
            keep: false,
            status: $status,
            restoredAt: $restoredAt,
            restoreDurationSeconds: $restoreDurationSeconds,
        ));

        return new BackupHistory($state);
    }
}

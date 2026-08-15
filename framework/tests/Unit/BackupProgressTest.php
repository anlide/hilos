<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupPhase;
use Hilos\Backup\BackupProgress;
use Hilos\Backup\RestorePhase;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the percentage and the time left of a running backup or restore.
 *
 * The numbers here are deliberately round - a hundred-second run, so a weight reads as a
 * percentage - because the frontend suite computes the same cases with the same numbers. That
 * pairing is what keeps the two implementations of one formula from drifting apart, so a change
 * to a case here belongs in `framework/frontend/core/test/admin/hilosBackups.test.ts` as well.
 */
final class BackupProgressTest extends TestCase
{
    /** A run long enough for one weight point to be one percent of it. */
    private const int RUN_SECONDS = 100;

    public function testEachCreatePhaseStartsAtTheWeightBehindIt(): void
    {
        $this->assertSame(0, $this->percentAt(BackupPhase::DUMPING, 0.0));
        $this->assertSame(70, $this->percentAt(BackupPhase::ARCHIVING, 0.0));
        $this->assertSame(95, $this->percentAt(BackupPhase::DIGESTING, 0.0));
        $this->assertSame(99, $this->percentAt(BackupPhase::PUBLISHING, 0.0));
    }

    public function testEachRestorePhaseStartsAtTheWeightBehindIt(): void
    {
        $this->assertSame(0, $this->percentAt(RestorePhase::VERIFYING, 0.0));
        $this->assertSame(5, $this->percentAt(RestorePhase::EXTRACTING, 0.0));
        $this->assertSame(20, $this->percentAt(RestorePhase::IMPORTING, 0.0));
        $this->assertSame(75, $this->percentAt(RestorePhase::MIGRATING, 0.0));
        $this->assertSame(85, $this->percentAt(RestorePhase::ANONYMIZING, 0.0));
        $this->assertSame(95, $this->percentAt(RestorePhase::REHYDRATING, 0.0));
    }

    public function testTimeInsideAPhaseFillsThatPhasesOwnShare(): void
    {
        // Dumping owns 70 of the 100 seconds, so half of it is 35 percent of the whole run.
        $this->assertSame(35, $this->percentAt(BackupPhase::DUMPING, 35.0));
        // Importing owns 55 seconds and starts at 20 percent: a fifth of it lands at 31.
        $this->assertSame(31, $this->percentAt(RestorePhase::IMPORTING, 11.0));
    }

    public function testAPhaseThatOutlivesItsShareStopsAtItsOwnCeiling(): void
    {
        $this->assertSame(70, $this->percentAt(BackupPhase::DUMPING, 5000.0));
        $this->assertSame(75, $this->percentAt(RestorePhase::IMPORTING, 5000.0));
    }

    public function testTheBarNeverFillsWhileTheRunIsStillGoing(): void
    {
        // Publishing ends the run arithmetically, but only a terminal phase may show a full bar.
        $this->assertSame(99, $this->percentAt(BackupPhase::PUBLISHING, 5000.0));
        $this->assertSame(100, $this->percentAt(RestorePhase::SUCCEEDED, 0.0));
        $this->assertSame(100, $this->percentAt(RestorePhase::FAILED, 0.0));
    }

    public function testWithoutAnEstimateThereIsNoPercentage(): void
    {
        $this->assertNull(BackupProgress::percent(
            BackupPhase::DUMPING->weightBefore(),
            BackupPhase::DUMPING->weight(),
            10.0,
            null,
        ));
        $this->assertNull(BackupProgress::percent(
            BackupPhase::DUMPING->weightBefore(),
            BackupPhase::DUMPING->weight(),
            10.0,
            0,
        ));
    }

    public function testTheTimeLeftCountsDownAndGoesNegativeOnAnOverrun(): void
    {
        $this->assertSame(60, BackupProgress::remainingSeconds(self::RUN_SECONDS, 40.0));
        $this->assertSame(-40, BackupProgress::remainingSeconds(self::RUN_SECONDS, 140.0));
        $this->assertNull(BackupProgress::remainingSeconds(null, 40.0));
    }

    /**
     * The percentage of a run of {@see RUN_SECONDS} that has spent the given time in the given phase.
     *
     * @param BackupPhase|RestorePhase $phase Phase the run is in
     * @param float $phaseElapsedSeconds Seconds spent in that phase
     * @return ?int Percent completed, or null when the formula reports none
     */
    private function percentAt(BackupPhase|RestorePhase $phase, float $phaseElapsedSeconds): ?int
    {
        return BackupProgress::percent(
            $phase->weightBefore(),
            $phase->weight(),
            $phaseElapsedSeconds,
            self::RUN_SECONDS,
        );
    }
}

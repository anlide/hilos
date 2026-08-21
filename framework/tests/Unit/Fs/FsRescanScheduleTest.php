<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs;

use Hilos\Fs\Watch\FsRescanSchedule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the coalescing window and the periodic floor of the rescan policy.
 *
 * The whole reason this policy is a separate object is visible here: the clock is an
 * argument, so a five-minute period and a one-second window are both tested in
 * microseconds and neither test sleeps or reaches for a seam over time.
 */
final class FsRescanScheduleTest extends TestCase
{
    /** Arbitrary microtime the schedules below start from; only differences matter. */
    private const float START = 1_000_000.0;

    public function testNothingIsDueOnAQuietSchedule(): void
    {
        $schedule = new FsRescanSchedule(self::START);

        $this->assertFalse($schedule->isDue(self::START));
        $this->assertFalse($schedule->isDue(self::START + FsRescanSchedule::COALESCE_WINDOW_SECONDS));
    }

    public function testAChangeIsNotDueBeforeItsWindowCloses(): void
    {
        $schedule = new FsRescanSchedule(self::START);
        $schedule->noteChanges(self::START);

        $this->assertFalse($schedule->isDue(self::START + FsRescanSchedule::COALESCE_WINDOW_SECONDS - 0.001));
    }

    public function testAChangeIsDueWhenItsWindowCloses(): void
    {
        $schedule = new FsRescanSchedule(self::START);
        $schedule->noteChanges(self::START);

        $this->assertTrue($schedule->isDue(self::START + FsRescanSchedule::COALESCE_WINDOW_SECONDS));
    }

    public function testLaterChangesDoNotPushTheWindowBack(): void
    {
        $schedule = new FsRescanSchedule(self::START);
        $schedule->noteChanges(self::START);
        $schedule->noteChanges(self::START + 0.5);
        $schedule->noteChanges(self::START + 0.9);

        $this->assertTrue($schedule->isDue(self::START + FsRescanSchedule::COALESCE_WINDOW_SECONDS));
    }

    public function testAScanClosesTheWindow(): void
    {
        $schedule = new FsRescanSchedule(self::START);
        $schedule->noteChanges(self::START);
        $schedule->noteScan(self::START + 0.2);

        $this->assertFalse($schedule->isDue(self::START + FsRescanSchedule::COALESCE_WINDOW_SECONDS));
    }

    public function testAChangeAfterAScanOpensAFreshWindow(): void
    {
        $schedule = new FsRescanSchedule(self::START);
        $schedule->noteScan(self::START);
        $schedule->noteChanges(self::START + 10.0);

        $this->assertFalse($schedule->isDue(self::START + 10.0));
        $this->assertTrue($schedule->isDue(self::START + 10.0 + FsRescanSchedule::COALESCE_WINDOW_SECONDS));
    }

    public function testTheFloorMakesAScanDueWithoutAnyChange(): void
    {
        $schedule = new FsRescanSchedule(self::START);

        $this->assertFalse($schedule->isDue(self::START + FsRescanSchedule::RESCAN_FLOOR_SECONDS - 0.001));
        $this->assertTrue($schedule->isDue(self::START + FsRescanSchedule::RESCAN_FLOOR_SECONDS));
    }

    public function testAScanRestartsTheFloor(): void
    {
        $schedule = new FsRescanSchedule(self::START);
        $schedule->noteScan(self::START + 100.0);

        $this->assertFalse($schedule->isDue(self::START + FsRescanSchedule::RESCAN_FLOOR_SECONDS));
        $this->assertTrue($schedule->isDue(self::START + 100.0 + FsRescanSchedule::RESCAN_FLOOR_SECONDS));
    }
}

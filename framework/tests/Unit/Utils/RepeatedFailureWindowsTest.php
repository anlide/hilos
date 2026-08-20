<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Utils;

use Hilos\Utils\RepeatedFailureWindows;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic both containing processes limit their journals by (HIL-574).
 *
 * It was written once for the master's client readers and is now shared with the worker
 * tick, so what it owes its owners is pinned here rather than through either of them:
 * how many of a kind go in full, what a closed window reports, and that a count taken is
 * never dropped whatever order the owner sweeps in.
 */
final class RepeatedFailureWindowsTest extends TestCase
{
    /** Failures per key written in full before the window starts counting */
    private const int BURST_LINES = 3;

    /** Length of a window in seconds */
    private const float WINDOW_SECONDS = 60.0;

    /** Key the assertions count under */
    private const string KEY = 'InvalidJsonException websocket';

    public function testTheFirstFewOfAKindAreAdmittedAndTheRestAreHeld(): void
    {
        $windows = $this->windows();
        $now = 1000.0;

        $admitted = 0;
        for ($failure = 0; $failure < self::BURST_LINES + 4; $failure++) {
            $admitted += (int)$windows->admits(self::KEY, $now);
        }

        $this->assertSame(self::BURST_LINES, $admitted);
    }

    public function testAClosedWindowSaysWhatItHeldBackAndIsForgotten(): void
    {
        $windows = $this->windows();
        $opened = 1000.0;
        $this->flood($windows, self::BURST_LINES + 4, $opened);

        $this->assertSame(
            [['key' => self::KEY, 'held' => 4]],
            $windows->closeExpired($opened + self::WINDOW_SECONDS),
        );
        $this->assertSame([], $windows->closeExpired($opened + self::WINDOW_SECONDS));
    }

    public function testAWindowStillRunningIsNotClosed(): void
    {
        $windows = $this->windows();
        $opened = 1000.0;
        $this->flood($windows, self::BURST_LINES + 1, $opened);

        $this->assertSame([], $windows->closeExpired($opened + self::WINDOW_SECONDS - 1.0));
    }

    public function testTheNextWindowAdmitsInFullAgain(): void
    {
        $windows = $this->windows();
        $opened = 1000.0;
        $this->flood($windows, self::BURST_LINES + 1, $opened);
        $windows->closeExpired($opened + self::WINDOW_SECONDS);

        $this->assertTrue($windows->admits(self::KEY, $opened + self::WINDOW_SECONDS));
    }

    /**
     * A limit shared by everything a process contains would let one loud cause silence
     * the rest, so what the key names is what counts as the same failure repeating.
     */
    public function testEachKeyIsCountedOnItsOwn(): void
    {
        $windows = $this->windows();
        $now = 1000.0;
        $this->flood($windows, self::BURST_LINES + 1, $now);

        $this->assertTrue($windows->admits('InvalidJsonException peer', $now));
    }

    /**
     * An owner that sweeps only from its loop still learns what the failure stream held
     * back while it was running: a window replaced by a fresh one is put aside, not
     * dropped.
     */
    public function testAWindowReplacedByANewFailureIsStillHandedOver(): void
    {
        $windows = $this->windows();
        $opened = 1000.0;
        $this->flood($windows, self::BURST_LINES + 2, $opened);

        $reopened = $opened + self::WINDOW_SECONDS;
        $windows->admits(self::KEY, $reopened);

        $this->assertSame([['key' => self::KEY, 'held' => 2]], $windows->closeExpired($reopened));
    }

    public function testResetForgetsWhatWasCounted(): void
    {
        $windows = $this->windows();
        $opened = 1000.0;
        $this->flood($windows, self::BURST_LINES + 4, $opened);

        $windows->reset();

        $this->assertSame([], $windows->closeExpired($opened + self::WINDOW_SECONDS));
        $this->assertTrue($windows->admits(self::KEY, $opened));
    }

    /**
     * Hands the limiter the same failure the given number of times.
     *
     * @param RepeatedFailureWindows $windows Limiter under test
     * @param int $times Number of failures to hand it
     * @param float $now Time all of them arrive at
     */
    private function flood(RepeatedFailureWindows $windows, int $times, float $now): void
    {
        for ($failure = 0; $failure < $times; $failure++) {
            $windows->admits(self::KEY, $now);
        }
    }

    /**
     * @return RepeatedFailureWindows Limiter with the thresholds the assertions assume
     */
    private function windows(): RepeatedFailureWindows
    {
        return new RepeatedFailureWindows(self::BURST_LINES, self::WINDOW_SECONDS);
    }
}

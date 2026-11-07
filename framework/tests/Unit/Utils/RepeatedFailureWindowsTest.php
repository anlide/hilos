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

    /** Closing a key must leave both kinds of pending windows for other keys intact. */
    public function testClosingOneKeyLeavesOtherCurrentAndReplacedWindowsPending(): void
    {
        $windows = $this->windows();
        $opened = 1000.0;
        $otherKey = 'InvalidJsonException peer';
        $this->flood($windows, self::BURST_LINES + 1, $opened);
        $this->flood($windows, self::BURST_LINES + 2, $opened, $otherKey);
        $reopened = $opened + self::WINDOW_SECONDS;
        $this->flood($windows, self::BURST_LINES + 3, $reopened);
        $this->flood($windows, self::BURST_LINES + 4, $reopened, $otherKey);

        $this->assertSame(
            [['key' => self::KEY, 'held' => 1], ['key' => self::KEY, 'held' => 3]],
            $windows->closeKey(self::KEY),
        );
        $this->assertSame([], $windows->closeKey('unknown failure'));
        $this->assertTrue($windows->hasPendingWindows());
        $this->assertSame([['key' => $otherKey, 'held' => 2]], $windows->closeExpired($reopened));
        $this->assertSame(
            [['key' => $otherKey, 'held' => 4]],
            $windows->closeExpired($reopened + self::WINDOW_SECONDS),
        );
        $this->assertFalse($windows->hasPendingWindows());
    }

    /** An explicit close owes the tail even before its window expires. */
    public function testClosingOneKeyHandsOverItsCurrentTail(): void
    {
        $windows = $this->windows();
        $this->flood($windows, self::BURST_LINES + 4, 1000.0);

        $this->assertSame([['key' => self::KEY, 'held' => 4]], $windows->closeKey(self::KEY));
        $this->assertFalse($windows->hasPendingWindows());
    }

    /** Several replacements of a key remain separate summaries beside its current tail. */
    public function testClosingOneKeyHandsOverAllItsReplacedAndCurrentWindows(): void
    {
        $windows = $this->windows();
        $opened = 1000.0;
        $this->flood($windows, self::BURST_LINES + 2, $opened);
        $this->flood($windows, self::BURST_LINES + 4, $opened + self::WINDOW_SECONDS);
        $this->flood($windows, self::BURST_LINES + 1, $opened + self::WINDOW_SECONDS * 2);

        $this->assertSame(
            [
                ['key' => self::KEY, 'held' => 2],
                ['key' => self::KEY, 'held' => 4],
                ['key' => self::KEY, 'held' => 1],
            ],
            $windows->closeKey(self::KEY),
        );
        $this->assertSame([], $windows->closeExpired($opened + self::WINDOW_SECONDS * 3));
    }

    /** A full close includes every key and may hand over a window that held nothing. */
    public function testClosingAllHandsOverEveryCurrentAndReplacedWindow(): void
    {
        $windows = $this->windows();
        $opened = 1000.0;
        $otherKey = 'InvalidJsonException peer';
        $this->flood($windows, self::BURST_LINES + 2, $opened);
        $this->flood($windows, self::BURST_LINES + 3, $opened, $otherKey);
        $reopened = $opened + self::WINDOW_SECONDS;
        $this->flood($windows, self::BURST_LINES + 4, $reopened);
        $windows->admits($otherKey, $reopened);

        $this->assertSame(
            [
                ['key' => self::KEY, 'held' => 2],
                ['key' => $otherKey, 'held' => 3],
                ['key' => self::KEY, 'held' => 4],
                ['key' => $otherKey, 'held' => 0],
            ],
            $windows->closeAll(),
        );
        $this->assertFalse($windows->hasPendingWindows());
    }

    /** A consumed key cannot be reported again by either explicit or expired closing. */
    public function testClosingOneKeyTwiceDoesNotRepeatClosedWindows(): void
    {
        $windows = $this->windows();
        $opened = 1000.0;
        $this->flood($windows, self::BURST_LINES + 1, $opened);
        $this->flood($windows, self::BURST_LINES + 2, $opened + self::WINDOW_SECONDS);

        $this->assertSame(
            [['key' => self::KEY, 'held' => 1], ['key' => self::KEY, 'held' => 2]],
            $windows->closeKey(self::KEY),
        );
        $this->assertSame([], $windows->closeKey(self::KEY));
        $this->assertSame([], $windows->closeAll());
        $this->assertSame([], $windows->closeExpired($opened + self::WINDOW_SECONDS * 2));
    }

    /** A full close consumes both stores, including windows already replaced. */
    public function testClosingAllTwiceDoesNotRepeatClosedWindows(): void
    {
        $windows = $this->windows();
        $opened = 1000.0;
        $this->flood($windows, self::BURST_LINES + 1, $opened);
        $this->flood($windows, self::BURST_LINES + 2, $opened + self::WINDOW_SECONDS);

        $this->assertSame(
            [['key' => self::KEY, 'held' => 1], ['key' => self::KEY, 'held' => 2]],
            $windows->closeAll(),
        );
        $this->assertSame([], $windows->closeAll());
        $this->assertSame([], $windows->closeKey(self::KEY));
        $this->assertSame([], $windows->closeExpired($opened + self::WINDOW_SECONDS * 2));
    }

    /** Closing early starts a fresh allowance even if the caller's time has not advanced. */
    public function testClosingOneKeyRestoresItsWholeBurst(): void
    {
        $windows = $this->windows();
        $now = 1000.0;
        $this->flood($windows, self::BURST_LINES + 1, $now);
        $windows->closeKey(self::KEY);

        for ($failure = 0; $failure < self::BURST_LINES; $failure++) {
            $this->assertTrue($windows->admits(self::KEY, $now));
        }
        $this->assertFalse($windows->admits(self::KEY, $now));
    }

    /** Every key regains its independent allowance after a full close. */
    public function testClosingAllRestoresTheWholeBurstForEveryKey(): void
    {
        $windows = $this->windows();
        $now = 1000.0;
        $otherKey = 'InvalidJsonException peer';
        $this->flood($windows, self::BURST_LINES + 1, $now);
        $this->flood($windows, self::BURST_LINES + 2, $now, $otherKey);
        $windows->closeAll();

        for ($failure = 0; $failure < self::BURST_LINES; $failure++) {
            $this->assertTrue($windows->admits(self::KEY, $now));
            $this->assertTrue($windows->admits($otherKey, $now));
        }
        $this->assertFalse($windows->admits(self::KEY, $now));
        $this->assertFalse($windows->admits($otherKey, $now));
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
     * @param string $key Failure kind whose window receives them
     */
    private function flood(RepeatedFailureWindows $windows, int $times, float $now, string $key = self::KEY): void
    {
        for ($failure = 0; $failure < $times; $failure++) {
            $windows->admits($key, $now);
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

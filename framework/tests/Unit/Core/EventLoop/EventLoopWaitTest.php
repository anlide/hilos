<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\EventLoop;

use Hilos\Core\EventLoop\EventLoop;
use PHPUnit\Framework\TestCase;

/**
 * The approved native-wait test: one test-owned 200 ms deadline and one runOnce().
 * Bringing it to now would remove the wait being tested. No production timer is
 * shortened, no child process is started, and no loop of dispatches hides early returns.
 */
final class EventLoopWaitTest extends TestCase
{
    /** One blocking pass ends at the timer, without intermediate master passes. */
    public function testOneNativeWaitReachesItsOwnDeadline(): void
    {
        $loop = new EventLoop();
        try {
            $calls = 0;
            $firedAt = null;
            $timer = $loop->createTimer(function () use (&$calls, &$firedAt): void {
                ++$calls;
                $firedAt = hrtime(true) / 1e9;
            });
            $deadline = hrtime(true) / 1e9 + 0.200;
            $timer->armAt($deadline);
            $this->assertFalse($loop->hasReadyWork());
            $loop->runOnce();
            $this->assertSame(1, $calls, 'runOnce must not return before its deadline fires');
            $this->assertNotNull($firedAt);
            $this->assertGreaterThanOrEqual($deadline, $firedAt);
            $this->assertFalse($loop->hasReadyWork());
        } finally {
            $loop->cleanup();
        }
    }
}

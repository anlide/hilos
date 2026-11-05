<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use Hilos\Core\Daemon\WatchdogWait;
use PHPUnit\Framework\TestCase;

final class WatchdogWaitTest extends TestCase
{
    /** A signal delivered before wait() must survive its nonblocking dispatch. */
    public function testSignalDeliveredBeforeWaitRemainsUntilConsumed(): void
    {
        $this->withWait(static function (WatchdogWait $wait): void {
            self::assertTrue(posix_kill(posix_getpid(), SIGHUP));

            $wait->wait(0.0);
            $wait->wait(0.0);

            self::assertSame([SIGHUP], $wait->consumeSignals());
            self::assertSame([], $wait->consumeSignals());
        });
    }

    /** An already due deadline permits the next pass without any signal. */
    public function testAlreadyDueDeadlineReturnsWithoutSignal(): void
    {
        $this->withWait(static function (WatchdogWait $wait): void {
            $wait->wait(0.0);

            self::assertSame([], $wait->consumeSignals());
        });
    }

    /**
     * Isolates the event base's signal ownership within this PHPUnit process.
     *
     * @param callable(WatchdogWait): void $assertWait Assertions using the installed wait
     */
    private function withWait(callable $assertWait): void
    {
        $signals = [SIGCHLD, SIGTERM, SIGINT, SIGHUP];
        $originalHandlers = [];
        foreach ($signals as $signal) {
            $originalHandlers[$signal] = pcntl_signal_get_handler($signal);
        }
        self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, $signals, $originalMask));
        $wait = null;

        try {
            $wait = new WatchdogWait();
            self::assertTrue(pcntl_sigprocmask(SIG_UNBLOCK, $signals));
            $assertWait($wait);
        } finally {
            try {
                $wait?->close();
            } finally {
                foreach ($originalHandlers as $signal => $handler) {
                    pcntl_signal($signal, $handler);
                }
                self::assertTrue(pcntl_sigprocmask(SIG_SETMASK, $originalMask));
            }
        }
    }
}

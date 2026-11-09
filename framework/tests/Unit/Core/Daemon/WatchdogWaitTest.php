<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use Hilos\Core\Daemon\WatchdogWait;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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

    public function testReadableBeforeWaitSurvivesSignalConsumption(): void
    {
        $this->withWait(static function (WatchdogWait $wait): void {
            [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            try {
                $wait->watchHeartbeat($reader);
                fwrite($writer, 'x');
                self::assertSame([], $wait->consumeSignals());
                self::assertTrue(new ReflectionProperty(WatchdogWait::class, 'heartbeatReady')->getValue($wait));
                $wait->wait(60.0); // Already readable; this must never enter a blocking wait.
                self::assertSame('x', fread($reader, 1));
            } finally {
                $wait->watchHeartbeat(null);
                fclose($reader);
                fclose($writer);
            }
        });
    }

    public function testEofDispatchIsOneShotUntilTheOwnerDetachesIt(): void
    {
        $this->withWait(static function (WatchdogWait $wait): void {
            [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            try {
                $wait->watchHeartbeat($reader);
                fclose($writer);
                $wait->wait(0.0);
                $wait->wait(0.0);
                self::assertSame([], $wait->consumeSignals());
                self::assertTrue(new ReflectionProperty(WatchdogWait::class, 'heartbeatReady')->getValue($wait));
                $wait->watchHeartbeat(null);
                self::assertFalse(new ReflectionProperty(WatchdogWait::class, 'heartbeatReady')->getValue($wait));
            } finally {
                $wait->watchHeartbeat(null);
                fclose($reader);
            }
        });
    }

    public function testDetachBeforePipeCloseAndRebindToANewChild(): void
    {
        $this->withWait(static function (WatchdogWait $wait): void {
            [$oldReader, $oldWriter] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $wait->watchHeartbeat($oldReader);
            $wait->watchHeartbeat(null);
            fclose($oldReader);
            fclose($oldWriter);
            [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            try {
                $wait->watchHeartbeat($reader);
                fwrite($writer, 'y');
                $wait->wait(0.0);
                self::assertTrue(new ReflectionProperty(WatchdogWait::class, 'heartbeatReady')->getValue($wait));
                self::assertTrue(posix_kill(posix_getpid(), SIGUSR1));
                self::assertTrue(posix_kill(posix_getpid(), SIGUSR2));
                $signals = $wait->consumeSignals();
                sort($signals);
                $expected = [SIGUSR1, SIGUSR2];
                sort($expected);
                self::assertSame($expected, $signals);
                $wait->close();
                self::assertNull(new ReflectionProperty(WatchdogWait::class, 'heartbeatEvent')->getValue($wait));
            } finally {
                $wait->watchHeartbeat(null);
                fclose($reader);
                fclose($writer);
            }
        });
    }

    /**
     * Isolates the event base's signal ownership within this PHPUnit process.
     *
     * @param callable(WatchdogWait): void $assertWait Assertions using the installed wait
     */
    private function withWait(callable $assertWait): void
    {
        $signals = [SIGCHLD, SIGTERM, SIGINT, SIGHUP, SIGUSR1, SIGUSR2];
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

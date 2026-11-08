<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core;

use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests status details captured by the process wrapper (HIL-1014).
 *
 * The terminating signal never leaves Process, so e2e cannot observe the cache and
 * the stand gateway does not fit: these tests must run real child processes here.
 */
final class ProcessTest extends TestCase
{
    private const float PROCESS_TIMEOUT_SECONDS = 3.0;

    private const int PROCESS_POLL_INTERVAL_US = 5_000;

    /** @param bool $observe Whether to close the child through the watchdog observation path */
    #[DataProvider('observationPaths')]
    public function testCapturesTerminatingSignal(bool $observe): void
    {
        $process = new Process('sh', ['-c', 'kill -9 $$']);

        $status = $this->waitUntilStopped($process, $observe);

        self::assertSame(9, $process->getTermSignal());
        if ($observe) {
            self::assertTrue($status[Process::STATUS_SIGNALED]);
            self::assertSame(9, $status[Process::STATUS_TERM_SIGNAL]);
        }
        self::assertFalse($process->isInFlight());
        self::assertSame($status, $process->observe());
        self::assertSame(9, $process->getTermSignal());
    }

    /** @param bool $observe Whether to close the child through the watchdog observation path */
    #[DataProvider('observationPaths')]
    public function testCapturesExitCodeWithoutTerminatingSignal(bool $observe): void
    {
        $process = new Process('sh', ['-c', 'printf final-out; printf final-err >&2; exit 3']);
        $loop = new EventLoop();
        try {
            $process->attachEventLoop($loop, $process->tick(...));
            $status = $this->waitUntilStopped($process, $observe);

            self::assertSame(3, $process->getExitCode());
            self::assertNull($process->getTermSignal());
            self::assertSame(3, $status[Process::STATUS_EXIT_CODE]);
            self::assertSame('final-out', $process->getStdOut());
            self::assertSame('final-err', $process->getStdErr());
            self::assertFalse($process->isInFlight());
            self::assertSame($status, $process->observe());
            self::assertSame(3, $process->getExitCode());
            self::assertNull($process->getTermSignal());
            self::assertSame(0, $loop->registeredCount());
            self::assertFalse($loop->hasReadyWork());
        } finally {
            $process->detachEventLoop();
            $loop->close();
        }
    }

    /** @return iterable<string, array{bool}> General tick and watchdog observation paths */
    public static function observationPaths(): iterable
    {
        yield 'tick' => [false];
        yield 'observe' => [true];
    }

    public function testPidIsAvailableOnlyWhileTheChildRuns(): void
    {
        $process = new Process('sh', ['-c', 'sleep 0.1']);
        self::assertGreaterThan(0, $process->getPid());

        $this->waitUntilStopped($process);
        self::assertNull($process->getPid());
    }

    /**
     * @param Process $process Process to poll
     * @param bool $observe Whether to observe and close without ticking stdin
     * @return array<string, mixed> Final status after closing the child
     */
    private function waitUntilStopped(Process $process, bool $observe = false): array
    {
        $deadline = microtime(true) + self::PROCESS_TIMEOUT_SECONDS;
        do {
            if ($observe) {
                $status = $process->observe();
            } else {
                $process->tick();
                $status = $process->getStatus();
            }
            if ($status[Process::STATUS_RUNNING] !== true) {
                return $status;
            }

            usleep(self::PROCESS_POLL_INTERVAL_US);
        } while (microtime(true) < $deadline);

        self::fail('the child process did not stop before the deadline');
    }
}

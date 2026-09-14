<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core;

use Hilos\Core\Process;
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

    public function testCapturesTerminatingSignal(): void
    {
        $process = new Process('sh', ['-c', 'kill -9 $$']);

        $this->waitUntilStopped($process);

        self::assertSame(9, $process->getTermSignal());
    }

    public function testCapturesExitCodeWithoutTerminatingSignal(): void
    {
        $process = new Process('sh', ['-c', 'exit 3']);

        $this->waitUntilStopped($process);

        self::assertSame(3, $process->getExitCode());
        self::assertNull($process->getTermSignal());
    }

    /**
     * @param Process $process Process to poll
     */
    private function waitUntilStopped(Process $process): void
    {
        $deadline = microtime(true) + self::PROCESS_TIMEOUT_SECONDS;
        do {
            $process->tick();
            if ($process->getStatus()[Process::STATUS_RUNNING] !== true) {
                return;
            }

            usleep(self::PROCESS_POLL_INTERVAL_US);
        } while (microtime(true) < $deadline);

        self::fail('the child process did not stop before the deadline');
    }
}

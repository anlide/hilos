<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core;

use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Daemon\MasterHeartbeat;
use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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
     * E2e cannot inspect the wrapper's private descriptor map, and no external service is missing.
     * This child proves the exact fd boundary and cache through bounded actual I/O/exit only.
     */
    public function testExtraDescriptorStaysSeparateFromStandardStreams(): void
    {
        $script = 'require "vendor/autoload.php";'
            . ' $heartbeat = Hilos\\Core\\Daemon\\MasterHeartbeat::fromArguments(["--watchdog-heartbeat"]);'
            . ' $heartbeat->onPassCompleted(hrtime(true) / 1e9); $heartbeat->close();'
            . ' echo "out"; fwrite(STDERR, "err"); fread(STDIN, 1);';
        $process = new Process('php', ['-r', $script], extraDescriptors: [
            MasterHeartbeat::DESCRIPTOR => [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE],
        ]);
        $pipe = $process->getPipe(MasterHeartbeat::DESCRIPTOR);
        self::assertIsResource($pipe);
        self::assertSame($pipe, $process->getPipe(MasterHeartbeat::DESCRIPTOR));
        self::assertFalse(stream_get_meta_data($pipe)['blocked']);
        self::assertNotSame($pipe, $process->getPipe(0));
        self::assertNotSame($pipe, $process->getPipe(1));
        self::assertNotSame($pipe, $process->getPipe(2));
        self::assertNull($process->getPipe(4));
        $deadline = microtime(true) + self::PROCESS_TIMEOUT_SECONDS;
        $mark = '';
        do {
            $process->tick();
            if (is_resource($pipe)) {
                $mark .= stream_get_contents($pipe);
            }
            if ($mark !== '') {
                break;
            }
            usleep(self::PROCESS_POLL_INTERVAL_US);
        } while (microtime(true) < $deadline);
        self::assertSame("\x01", $mark, 'tick must not consume fd 3');
        $process->sendInput('x');
        $this->waitUntilStopped($process, true);
        self::assertSame('out', $process->getStdOut());
        self::assertSame('err', $process->getStdErr());
        self::assertNull($process->getPipe(MasterHeartbeat::DESCRIPTOR));

        $this->expectException(CouldNotStartException::class);
        new Process('php', extraDescriptors: [0 => [Process::DESCRIPTOR_PIPE, Process::PIPE_READ]]);
    }

    /**
     * E2e cannot observe who consumes proc_get_status or closes its cached handle; no stand gateway can expose it.
     * A blocked stdin read keeps the child alive until the request; bounded exit polling waits only for actual SIGKILL.
     */
    public function testKillUsesCurrentSnapshotOnceAndReapsOnlyOnObservation(): void
    {
        $process = new Process('php', ['-r', 'fread(STDIN, 1);']);
        $status = $process->observe();
        self::assertTrue($status[Process::STATUS_RUNNING]);
        $cached = new ReflectionProperty(Process::class, 'lastStatus');
        $cached->setValue($process, [Process::STATUS_RUNNING => 'unchanged']);
        $process->killFromStatus($status);
        $process->killFromStatus($status);
        self::assertSame([Process::STATUS_RUNNING => 'unchanged'], $cached->getValue($process), 'kill takes no second status');
        self::assertTrue($process->isInFlight());
        self::assertIsResource($process->getPipe(0));
        self::assertTrue(new ReflectionProperty(Process::class, 'killSent')->getValue($process));
        $this->waitUntilStopped($process, true);
        self::assertSame(SIGKILL, $process->getTermSignal());
        self::assertFalse($process->isInFlight());
        self::assertNull($process->getPipe(0));
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

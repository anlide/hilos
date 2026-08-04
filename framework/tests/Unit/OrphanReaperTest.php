<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\OrphanReaper;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Process;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the watchdog's orphan sweep (HIL-450).
 *
 * Exercised against real child processes rather than a mocked /proc: the whole point
 * of the reaper is that it reads the live process table, so a fake would only prove
 * the parser matches the fake. Each test spawns its own `sleep`, which is a direct
 * child of the PHPUnit process exactly as an orphaned worker is a direct child of the
 * watchdog after re-parenting.
 *
 * A spawned child is not immediately the process it was asked to be, and that gap used
 * to make this suite flake. Process runs proc_open() without a shell, so the call
 * returns as soon as the fork is done — before the child reaches execve(). Until it
 * does, the child is still a copy of the PHPUnit process and /proc/<pid>/cmdline reads
 * `php ... phpunit`, not `sleep 30`. Hence the barrier in spawn(): it waits for the
 * child to be listed by the reaper carrying its post-exec command line, which is
 * exactly what the assertions below read back. Do not replace that wait with a blind
 * sleep, and do not drop it as redundant — it is the thing being synchronised on.
 */
final class OrphanReaperTest extends TestCase
{
    /** How long a spawned child may take to reach its post-exec command line. */
    private const int SPAWN_TIMEOUT_SECONDS = 5;

    /** Poll interval while waiting for a spawned child to become visible. */
    private const int SPAWN_POLL_INTERVAL_US = 5_000;

    /** @var list<Process> Processes spawned by the running test, stopped in teardown */
    private array $spawned = [];

    protected function tearDown(): void
    {
        foreach ($this->spawned as $process) {
            $process->halt();
        }
        $this->spawned = [];
        parent::tearDown();
    }

    /**
     * @throws FailedToGetStatusException When the spawned child's status cannot be read
     */
    public function testFindsASpawnedChild(): void
    {
        $pid = $this->spawn();

        self::assertArrayHasKey($pid, new OrphanReaper()->findChildren(), 'the spawned sleep should be listed');
    }

    /**
     * @throws FailedToGetStatusException When the spawned child's status cannot be read
     */
    public function testReportsTheChildCommandLine(): void
    {
        $pid = $this->spawn();

        self::assertStringContainsString('sleep', new OrphanReaper()->findChildren()[$pid]);
    }

    /**
     * @throws FailedToGetStatusException When the spawned child's status cannot be read
     */
    public function testExcludesTheGivenPid(): void
    {
        $pid = $this->spawn();

        self::assertArrayNotHasKey($pid, new OrphanReaper()->findChildren($pid));
    }

    /**
     * @throws FailedToGetStatusException When the spawned child's status cannot be read
     */
    public function testReapTerminatesTheChild(): void
    {
        $pid = $this->spawn();
        $reaper = new OrphanReaper();

        // `sleep` honours SIGTERM, so nothing should need the follow-up SIGKILL.
        self::assertSame(0, $reaper->reap());
        self::assertArrayNotHasKey($pid, $reaper->findChildren(), 'the child should be gone');
    }

    /**
     * @throws FailedToGetStatusException When the spawned child's status cannot be read
     */
    public function testReapSparesTheExcludedPid(): void
    {
        $pid = $this->spawn();
        $reaper = new OrphanReaper();

        $reaper->reap($pid);

        self::assertArrayHasKey($pid, $reaper->findChildren(), 'the excluded child should survive');
    }

    public function testReapIsANoOpWithoutChildren(): void
    {
        self::assertSame(0, new OrphanReaper()->reap());
    }

    /**
     * Spawns a long-lived child process for the running test and waits until it is ready.
     *
     * Ready means visible to the reaper under the command line it will keep, so the
     * caller can assert on the listing without racing the child's own startup.
     *
     * @return int Process id of the spawned child
     * @throws FailedToGetStatusException When the child's status cannot be read
     */
    private function spawn(): int
    {
        // Registered before the wait, so teardown still kills the child if the wait fails.
        $process = new Process('sleep', ['30']);
        $this->spawned[] = $process;

        // proc_open() fills the pid in right after the fork, well before execve() lands.
        $pid = (int) $process->getStatus()['pid'];

        $deadline = microtime(true) + self::SPAWN_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            $children = new OrphanReaper()->findChildren();
            if (isset($children[$pid]) && str_contains($children[$pid], 'sleep')) {
                return $pid;
            }

            usleep(self::SPAWN_POLL_INTERVAL_US);
        }

        self::fail(sprintf(
            'the spawned child pid=%d never appeared with its post-exec cmdline within %ds',
            $pid,
            self::SPAWN_TIMEOUT_SECONDS,
        ));
    }
}

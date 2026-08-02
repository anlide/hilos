<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\OrphanReaper;
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
 */
final class OrphanReaperTest extends TestCase
{
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

    public function testFindsASpawnedChild(): void
    {
        $this->spawn();

        $children = new OrphanReaper()->findChildren();

        self::assertNotSame([], $children);
        self::assertNotNull($this->pidOfSleep($children), 'the spawned sleep should be listed');
    }

    public function testReportsTheChildCommandLine(): void
    {
        $this->spawn();

        $children = new OrphanReaper()->findChildren();
        $pid = $this->pidOfSleep($children);

        self::assertNotNull($pid);
        self::assertStringContainsString('sleep', $children[$pid]);
    }

    public function testExcludesTheGivenPid(): void
    {
        $this->spawn();
        $reaper = new OrphanReaper();
        $pid = $this->pidOfSleep($reaper->findChildren());

        self::assertNotNull($pid);
        self::assertArrayNotHasKey($pid, $reaper->findChildren($pid));
    }

    public function testReapTerminatesTheChild(): void
    {
        $this->spawn();
        $reaper = new OrphanReaper();

        $killed = $reaper->reap();

        // `sleep` honours SIGTERM, so nothing should need the follow-up SIGKILL.
        self::assertSame(0, $killed);
        self::assertNull($this->pidOfSleep($reaper->findChildren()), 'the child should be gone');
    }

    public function testReapSparesTheExcludedPid(): void
    {
        $this->spawn();
        $reaper = new OrphanReaper();
        $pid = $this->pidOfSleep($reaper->findChildren());

        self::assertNotNull($pid);
        $reaper->reap($pid);

        self::assertArrayHasKey($pid, $reaper->findChildren(), 'the excluded child should survive');
    }

    public function testReapIsANoOpWithoutChildren(): void
    {
        self::assertSame(0, new OrphanReaper()->reap());
    }

    /**
     * Spawns a long-lived child process for the running test.
     */
    private function spawn(): void
    {
        $this->spawned[] = new Process('sleep', ['30']);
    }

    /**
     * Picks the spawned sleep out of a child listing.
     *
     * @param array<int, string> $children Command line keyed by process id
     * @return ?int Process id of the sleep child, or null when it is not listed
     */
    private function pidOfSleep(array $children): ?int
    {
        foreach ($children as $pid => $cmdline) {
            if (str_contains($cmdline, 'sleep')) {
                return $pid;
            }
        }

        return null;
    }
}

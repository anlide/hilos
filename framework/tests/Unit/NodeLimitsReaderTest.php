<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\NodeLimitsReader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Kernel pseudo-file fixtures for cgroup limits and machine statistics. */
final class NodeLimitsReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-limits-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testQuotaAndMemoryUseTheTightestAncestor(): void
    {
        $this->put('proc/self/cgroup', "0::/parent/child\n");
        $this->put('cgroup/cpu.max', "400000 100000\n");
        $this->put('cgroup/parent/cpu.max', "150000 100000\n");
        $this->put('cgroup/parent/child/cpu.max', "300000 100000\n");
        $this->put('cgroup/memory.max', "8388608\n");
        $this->put('cgroup/parent/memory.max', "3145728\n");
        $this->put('cgroup/parent/child/memory.max', "6291456\n");

        self::assertSame(1.5, $this->reader()->cpuQuotaCores());
        self::assertSame(3145728, $this->reader()->cgroupMemoryMax());
    }

    public function testRootQuotaAndMissingOrUnlimitedLevels(): void
    {
        $this->put('proc/self/cgroup', "0::/group\n");
        $this->put('cgroup/cpu.max', "250000 100000\n");
        $this->put('cgroup/memory.max', "max\n");

        self::assertSame(2.5, $this->reader()->cpuQuotaCores());
        self::assertNull($this->reader()->cgroupMemoryMax());

        $this->put('cgroup/cpu.max', "max 100000\n");
        self::assertNull($this->reader()->cpuQuotaCores());
        unlink($this->root . '/cgroup/cpu.max');
        self::assertNull($this->reader()->cpuQuotaCores());
    }

    public function testV1AndMalformedLimitsRemainUnknown(): void
    {
        $this->put('proc/self/cgroup', "2:cpu:/old\n");
        $this->put('cgroup/cpu.max', "100000 100000\n");
        self::assertNull($this->reader()->cpuQuotaCores());
        self::assertNull($this->reader()->cgroupMemoryMax());

        $this->put('proc/self/cgroup', "0::/\n");
        $this->put('cgroup/cpu.max', "bad data\n");
        $this->put('cgroup/memory.max', "broken\n");
        self::assertNull($this->reader()->cpuQuotaCores());
        self::assertNull($this->reader()->cgroupMemoryMax());
    }

    public function testAllowedCpuRangesAndPhysicalMemory(): void
    {
        $this->put('proc/self/status', "Name: test\nCpus_allowed_list:\t0-3,6\n");
        $this->put('proc/meminfo', "MemTotal:       4096 kB\nMemFree: 1000 kB\n");
        self::assertSame(5, $this->reader()->allowedCpus());
        self::assertSame(4194304, $this->reader()->physicalMemory());

        $this->put('proc/self/status', "Cpus_allowed_list:\t0-3,bad\n");
        $this->put('proc/meminfo', "MemTotal: no number\n");
        self::assertNull($this->reader()->allowedCpus());
        self::assertNull($this->reader()->physicalMemory());
    }

    public function testMachineCpuRejectsMissingOrMalformedStat(): void
    {
        self::assertNull($this->reader()->machineCpu());
        $this->put('proc/stat', "cpu 100 2 3 50 4 5 6 7 0 0\n");
        self::assertSame(5, $this->reader()->machineCpu()?->irq);
        $this->put('proc/stat', "cpu bad\n");
        self::assertNull($this->reader()->machineCpu());
        $this->put('proc/stat', "cpu 100 2 3 50 4 5 6 7 garbage\n");
        self::assertNull($this->reader()->machineCpu());
    }

    private function reader(): NodeLimitsReader
    {
        return new NodeLimitsReader($this->root . '/proc', $this->root . '/cgroup');
    }

    private function put(string $path, string $contents): void
    {
        $target = $this->root . '/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), recursive: true);
        }
        file_put_contents($target, $contents);
    }
}

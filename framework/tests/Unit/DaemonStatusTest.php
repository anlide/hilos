<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Core\Daemon\NodeLimitsReader;
use Hilos\Core\Daemon\ProcessMetricsReader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** A real sampler over synthetic kernel pseudo-files and a controlled clock. */
final class DaemonStatusTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-sample-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->put('proc/self/smaps', "KernelPageSize:        4 kB\n");
        $this->put('proc/self/cgroup', "0::/\n");
        $this->put('proc/self/status', "Cpus_allowed_list:\t0-2\n");
        $this->put('proc/meminfo', "MemTotal: 8192 kB\n");
        $this->put('cgroup/cpu.max', "150000 100000\n");
        $this->put('cgroup/memory.max', "4194304\n");
        $this->put('proc/stat', "cpu 100 0 0 100 0 0 0 0\n");
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

    public function testFirstAndSecondSamplesUseProcessDeltasAndRoundedMemory(): void
    {
        $this->process(10, 100, 40, 300);
        $this->process(20, 200, 50, 600);
        $status = $this->sampler();
        self::assertNull($status->last()->daemonCores);
        self::assertNull($status->last()->masterMemory);

        $first = $status->sample(1000.0, [20]);
        self::assertNull($first->daemonCores);
        self::assertNull($first->masterCores);
        self::assertNull($first->machineLoad);
        self::assertSame(1.5, $first->cores);
        self::assertSame(DaemonMeasurements::CORES_QUOTA, $first->coresBound);
        self::assertSame(1048576, $first->masterMemory);
        self::assertSame(2097152, $first->workersMemory);
        self::assertSame(1, $first->workersMeasured);
        self::assertSame(4194304, $first->memoryCeiling);
        self::assertSame(DaemonMeasurements::CEILING_CGROUP, $first->memoryCeilingBound);

        $this->process(10, 155, 45, 550);
        $this->process(20, 285, 55, 700);
        $this->put('proc/stat', "cpu 140 0 0 150 0 10 0 0\n");
        $second = $status->sample(1005.0, [20]);
        self::assertSame(0.12, $second->masterCores);
        self::assertSame(0.3, $second->daemonCores);
        self::assertSame(50, $second->machineLoad);
        self::assertSame(2097152, $second->masterMemory);
        self::assertSame($second, $status->last());
    }

    public function testNewReusedAndDeadWorkersDoNotCarryOldCpu(): void
    {
        $this->process(10, 100, 0, 300);
        $this->process(20, 100, 0, 300);
        $status = $this->sampler();
        $status->sample(1000.0, [20]);

        $this->process(10, 105, 0, 300);
        $this->process(20, 105, 0, 300);
        $this->process(30, 900, 0, 300);
        self::assertSame(0.02, $status->sample(1005.0, [20, 30])->daemonCores);

        $this->process(10, 110, 0, 300);
        $this->process(20, 1000, 0, 300, 999);
        unlink($this->root . '/proc/30/stat');
        $third = $status->sample(1010.0, [20, 30]);
        self::assertSame(0.01, $third->daemonCores);
        self::assertSame(1, $third->workersMeasured);
    }

    public function testCpuAndMemoryBoundsUseTheLowerSourceAndTiesPreferCgroup(): void
    {
        $this->process(10, 1, 1, 300);
        $status = $this->sampler();
        $this->put('cgroup/cpu.max', "500000 100000\n");
        $this->put('cgroup/memory.max', "16777216\n");
        $sample = $status->sample(1000.0, []);
        self::assertSame(3.0, $sample->cores);
        self::assertSame(DaemonMeasurements::CORES_CPUS, $sample->coresBound);
        self::assertSame(8388608, $sample->memoryCeiling);
        self::assertSame(DaemonMeasurements::CEILING_MACHINE, $sample->memoryCeilingBound);

        $this->put('cgroup/cpu.max', "300000 100000\n");
        $this->put('cgroup/memory.max', "8388608\n");
        $sample = $status->sample(1005.0, []);
        self::assertSame(DaemonMeasurements::CORES_QUOTA, $sample->coresBound);
        self::assertSame(DaemonMeasurements::CEILING_CGROUP, $sample->memoryCeilingBound);
    }

    public function testUnknownClockAndPageSizeStayUnknown(): void
    {
        unlink($this->root . '/proc/self/smaps');
        $this->process(10, 100, 0, 300);
        $this->process(20, 100, 0, 300);
        $status = $this->sampler(ticksPerSecond: 0);
        $status->sample(1000.0, [20]);
        $this->process(10, 200, 0, 300);
        $result = $status->sample(1005.0, [20]);
        self::assertNull($result->daemonCores);
        self::assertNull($result->masterCores);
        self::assertNull($result->masterMemory);
        self::assertNull($result->workersMemory);
        self::assertSame(0, $result->workersMeasured);
    }

    public function testSmallCpuDeltaAndMachinePercentageRoundAtTheSource(): void
    {
        $this->process(10, 100, 0, 255);
        $status = $this->sampler();
        $status->sample(1000.0, []);
        $this->process(10, 105, 0, 511);
        $this->put('proc/stat', "cpu 101 0 0 102 0 0 0 0\n");
        $sample = $status->sample(1005.0, []);
        self::assertSame(0.01, $sample->masterCores);
        self::assertSame(0.01, $sample->daemonCores);
        self::assertSame(33, $sample->machineLoad);
        self::assertSame(1048576, $sample->masterMemory);
    }

    public function testUnlimitedPhpMemoryLimitIsUnknown(): void
    {
        $old = ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        try {
            $this->process(10, 1, 1, 300);
            self::assertNull($this->sampler()->sample(1000.0, [])->phpMemoryLimit);
        } finally {
            ini_set('memory_limit', (string)$old);
        }
    }

    private function sampler(int $ticksPerSecond = 100): DaemonStatus
    {
        return new DaemonStatus(
            new ProcessMetricsReader($this->root . '/proc', $ticksPerSecond),
            new NodeLimitsReader($this->root . '/proc', $this->root . '/cgroup'),
            10,
            1000.0,
        );
    }

    private function process(int $pid, int $utime, int $stime, int $rss, int $start = 50): void
    {
        $fields = array_fill(0, 22, '0');
        $fields[0] = 'S';
        $fields[11] = (string)$utime;
        $fields[12] = (string)$stime;
        $fields[19] = (string)$start;
        $fields[21] = (string)$rss;
        $this->put("proc/{$pid}/stat", $pid . ' (worker ) name) ' . implode(' ', $fields));
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

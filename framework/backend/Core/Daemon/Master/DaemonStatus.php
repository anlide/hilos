<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Master;

use Hilos\Core\Daemon\Cli\CpuStats;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Daemon\NodeLimitsReader;
use Hilos\Core\Daemon\ProcessMetricsReader;
use Hilos\Core\Daemon\ProcessSample;
use Hilos\Core\Exception\InvalidFormatException;

/** Samples the master, its workers, and node limits on one clock. */
class DaemonStatus
{
    private const int BYTES_PER_MIB = 1048576;

    private float $startTime;
    private ?float $previousTime = null;
    /** @var array<int, ProcessSample> */
    private array $previousProcesses = [];
    private ?CpuStats $previousCpuStats = null;
    private DaemonMeasurements $last;
    private ProcessMetricsReader $metrics;
    private NodeLimitsReader $limits;
    private int $masterPid;

    /**
     * @param ProcessMetricsReader $metrics Reader of master and worker process metrics
     * @param ?NodeLimitsReader $limits Node pseudo-file reader
     * @param ?int $masterPid Master process id
     * @param ?float $startTime Explicit start time for tests
     * @throws InvalidFormatException When the initial measurement violates its value contract
     */
    public function __construct(
        ProcessMetricsReader $metrics,
        ?NodeLimitsReader $limits = null,
        ?int $masterPid = null,
        ?float $startTime = null,
    ) {
        $this->metrics = $metrics;
        $this->limits = $limits ?? new NodeLimitsReader();
        $this->masterPid = $masterPid ?? getmypid();
        $this->startTime = $startTime ?? microtime(true);
        $this->last = new DaemonMeasurements(
            startedAt: (int)$this->startTime,
            cores: null,
            coresBound: null,
            daemonCores: null,
            masterCores: null,
            machineLoad: null,
            masterMemory: null,
            workersMemory: null,
            workersMeasured: 0,
            memoryCeiling: null,
            memoryCeilingBound: null,
            phpMemoryLimit: null,
        );
    }

    /**
     * @param float $now Sample clock in seconds
     * @param list<int> $workerPids Live worker process ids
     * @return DaemonMeasurements Rounded sample stored for all consumers
     * @throws InvalidFormatException When a sampled measurement violates its value contract
     */
    public function sample(float $now, array $workerPids): DaemonMeasurements
    {
        $master = $this->metrics->readSample($this->masterPid);
        $current = $master === null ? [] : [$this->masterPid => $master];
        $workerBytes = 0;
        $workersMeasured = 0;
        $workerMemoryKnown = $this->metrics->pageSizeBytes() !== null;
        foreach (array_unique($workerPids) as $pid) {
            if ($pid === $this->masterPid) {
                continue;
            }
            $process = $this->metrics->readSample($pid);
            if ($process === null) {
                continue;
            }
            $current[$pid] = $process;
            if ($process->residentBytes === null) {
                continue;
            }
            if ($process->residentBytes > PHP_INT_MAX - $workerBytes) {
                $workerMemoryKnown = false;
                continue;
            }
            $workerBytes += $process->residentBytes;
            $workersMeasured++;
        }

        $masterCores = null;
        $daemonCores = null;
        $elapsed = $this->previousTime === null ? 0.0 : $now - $this->previousTime;
        $ticksPerSecond = $this->metrics->ticksPerSecond();
        if ($master !== null && $this->previousTime !== null && $elapsed > 0 && $ticksPerSecond !== null) {
            $masterTicks = $this->tickDelta($this->masterPid, $master);
            $totalTicks = $masterTicks;
            foreach ($current as $pid => $process) {
                if ($pid !== $this->masterPid) {
                    $totalTicks += $this->tickDelta($pid, $process);
                }
            }
            $masterCores = round($masterTicks / $ticksPerSecond / $elapsed, 2);
            $daemonCores = round($totalTicks / $ticksPerSecond / $elapsed, 2);
        }

        $machineCpu = $this->limits->machineCpu();
        $usage = $machineCpu !== null && $this->previousCpuStats !== null
            ? $machineCpu->getUsagePercentage($this->previousCpuStats) : null;
        if ($usage !== null && (!is_finite($usage) || $usage < 0 || $usage > 100)) {
            $usage = null;
        }
        $this->previousCpuStats = $machineCpu;
        $this->previousTime = $now;
        $this->previousProcesses = $current;

        $quota = $this->limits->cpuQuotaCores();
        $cpus = $this->limits->allowedCpus();
        $cores = $quota !== null && ($cpus === null || $quota <= $cpus) ? $quota : $cpus;
        $coresBound = $cores === null ? null : ($cores === $quota ? DaemonMeasurements::CORES_QUOTA : DaemonMeasurements::CORES_CPUS);
        $cgroupMemory = $this->limits->cgroupMemoryMax();
        $physicalMemory = $this->limits->physicalMemory();
        $ceiling = $cgroupMemory !== null && ($physicalMemory === null || $cgroupMemory <= $physicalMemory)
            ? $cgroupMemory : $physicalMemory;
        $ceilingBound = $ceiling === null ? null : ($ceiling === $cgroupMemory
            ? DaemonMeasurements::CEILING_CGROUP : DaemonMeasurements::CEILING_MACHINE);
        $phpLimit = ini_parse_quantity((string)ini_get('memory_limit'));

        $this->last = new DaemonMeasurements(
            startedAt: (int)$this->startTime,
            cores: $cores === null ? null : max(0.01, round($cores, 2)),
            coresBound: $coresBound,
            daemonCores: $daemonCores,
            masterCores: $masterCores,
            machineLoad: $usage === null ? null : (int)round($usage),
            masterMemory: self::roundedMemory($master?->residentBytes),
            workersMemory: $workerMemoryKnown ? self::roundedMemory($workerBytes) : null,
            workersMeasured: $workerMemoryKnown ? $workersMeasured : 0,
            memoryCeiling: $ceiling,
            memoryCeilingBound: $ceilingBound,
            phpMemoryLimit: $phpLimit > 0 ? $phpLimit : null,
        );
        return $this->last;
    }

    /** @return DaemonMeasurements Last completed sample, initially unknown except start time */
    public function last(): DaemonMeasurements
    {
        return $this->last;
    }

    /** @return int Uptime in seconds */
    public function getUptime(): int
    {
        return (int)(microtime(true) - $this->startTime);
    }

    /**
     * @param int $pid Process id to compare
     * @param ProcessSample $process Current process sample
     * @return int CPU tick increase for a process with the same kernel start tick
     */
    private function tickDelta(int $pid, ProcessSample $process): int
    {
        $previous = $this->previousProcesses[$pid] ?? null;
        return $previous !== null && $previous->startTicks === $process->startTicks
            ? max(0, $process->cpuTicks - $previous->cpuTicks) : 0;
    }

    /**
     * @param ?int $bytes Resident bytes, or null when unavailable
     * @return ?int Resident bytes floored to whole MiB
     */
    private static function roundedMemory(?int $bytes): ?int
    {
        return $bytes === null ? null : intdiv($bytes, self::BYTES_PER_MIB) * self::BYTES_PER_MIB;
    }
}

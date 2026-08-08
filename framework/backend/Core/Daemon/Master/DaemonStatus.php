<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Master;

use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Cli\CpuStats;

/**
 * Holds daemon status information.
 *
 * Contains runtime information about daemon state that can be
 * exposed via HTTP status endpoint.
 */
class DaemonStatus
{
    /** @var float Daemon start time (microtime) */
    private float $startTime;

    /** @var ?CpuStats Previous /proc/stat sample for delta-based CPU calculation */
    private ?CpuStats $previousCpuStats = null;

    /** @var int Memory usage in bytes */
    private(set) int $memoryUsage = 0;

    /** @var float CPU usage percentage */
    private(set) float $cpuUsage = 0.0;

    /** @var int Number of active regular workers */
    private(set) int $workersRegular = 0;

    /** @var int Number of active monopolistic workers */
    private(set) int $workersMonopolistic = 0;

    /** @var int Maximum number of regular workers */
    private(set) int $workersMaxRegular = 0;

    /**
     * Creates daemon status with optional start time.
     *
     * @param ?float $startTime Optional start time (for creating from DTO)
     */
    public function __construct(?float $startTime = null)
    {
        $this->startTime = $startTime ?? microtime(true);
    }

    /**
     * Refreshes memory usage and samples CPU usage as the delta since the
     * previous call.
     *
     * The first call, and non-Linux hosts without a readable /proc/stat, leave
     * cpuUsage at its last-known value (0.0 until the first successful delta).
     */
    public function update(): void
    {
        $this->memoryUsage = memory_get_usage(true);
        $this->updateCpuUsage();
    }

    /**
     * Get uptime in seconds.
     *
     * @return int Uptime in seconds
     */
    public function getUptime(): int
    {
        return (int)(microtime(true) - $this->startTime);
    }

    /**
     * Create DaemonStatus from DTO.
     *
     * @param DaemonStatusDTO $dto Source DTO
     * @return self DaemonStatus instance
     */
    public static function fromDTO(DaemonStatusDTO $dto): self
    {
        // Calculate start time from uptime
        $startTime = microtime(true) - $dto->uptime;

        $status = new self($startTime);
        $status->memoryUsage = $dto->memory;
        $status->cpuUsage = $dto->cpu;
        $status->workersRegular = $dto->workersRegular;
        $status->workersMonopolistic = $dto->workersMonopolistic;
        $status->workersMaxRegular = $dto->workersMaxRegular;

        return $status;
    }

    /**
     * Samples CPU usage from /proc/stat against the previous sample and stores
     * the current sample for the next call.
     *
     * Leaves cpuUsage unchanged when no sample is available (non-Linux) or the
     * delta is not yet meaningful (first call, or zero total time between
     * samples).
     */
    private function updateCpuUsage(): void
    {
        $current = $this->readCpuStats();
        if ($current === null) {
            return;
        }

        if ($this->previousCpuStats !== null) {
            $usage = $current->getUsagePercentage($this->previousCpuStats);
            if ($usage !== null) {
                $this->cpuUsage = $usage;
            }
        }

        $this->previousCpuStats = $current;
    }

    /**
     * Reads the aggregate CPU sample from the first line of /proc/stat.
     *
     * @return ?CpuStats Current sample, or null when /proc/stat is unreadable (non-Linux hosts)
     */
    private function readCpuStats(): ?CpuStats
    {
        // warning-suppressed: /proc is absent off Linux, the caller reads null as CPU usage unknown
        $contents = @file_get_contents('/proc/stat', false, null, 0, 256);
        if ($contents === false) {
            return null;
        }

        // The aggregate "cpu" line is space-padded; collapse whitespace runs so
        // CpuStats::fromProcStat() splits the columns at the right offsets.
        $cpuLine = preg_replace('/\s+/', ' ', trim(explode("\n", $contents, 2)[0]));
        if ($cpuLine === null || !str_starts_with($cpuLine, 'cpu ')) {
            return null;
        }

        return CpuStats::fromProcStat($cpuLine);
    }
}

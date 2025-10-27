<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Master;

/**
 * DaemonStatus - Holds daemon status information
 *
 * Contains runtime information about daemon state that can be
 * exposed via HTTP status endpoint.
 */
class DaemonStatus
{
    /** @var bool Daemon running state */
    private bool $running = true;

    /** @var int Daemon process ID */
    private int $pid;

    /** @var float Daemon start time (microtime) */
    private float $startTime;

    /** @var int Memory usage in bytes */
    private int $memoryUsage = 0;

    /** @var int CPU usage percentage */
    private float $cpuUsage = 0.0;

    /**
     * DaemonStatus constructor
     */
    public function __construct()
    {
        $this->pid = getmypid();
        $this->startTime = microtime(true);
    }

    /**
     * Update status information
     */
    public function update(): void
    {
        $this->memoryUsage = memory_get_usage(true);
        // CPU usage calculation would require more complex logic
        // For now just set to 0
        $this->cpuUsage = 0.0;
    }

    /**
     * Get uptime in seconds
     *
     * @return int Uptime seconds
     */
    public function getUptime(): int
    {
        return (int)(microtime(true) - $this->startTime);
    }

    /**
     * Get status as array
     *
     * @return array Status data
     */
    public function toArray(): array
    {
        return [
            'running' => $this->running,
            'pid' => $this->pid,
            'uptime' => $this->getUptime(),
            'memory' => $this->memoryUsage,
            'cpu' => $this->cpuUsage,
            'timestamp' => time(),
        ];
    }

    /**
     * Set running state
     *
     * @param bool $running Running state
     */
    public function setRunning(bool $running): void
    {
        $this->running = $running;
    }

    /**
     * Check if running
     *
     * @return bool Running state
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get memory usage
     *
     * @return int Memory in bytes
     */
    public function getMemoryUsage(): int
    {
        return $this->memoryUsage;
    }

    /**
     * Get CPU usage
     *
     * @return float CPU percentage
     */
    public function getCpuUsage(): float
    {
        return $this->cpuUsage;
    }

    /**
     * Get PID
     *
     * @return int Process ID
     */
    public function getPid(): int
    {
        return $this->pid;
    }
}


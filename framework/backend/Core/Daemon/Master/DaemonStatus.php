<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Master;

use Hilos\Core\CLI\DTO\DaemonStatusDTO;

/**
 * DaemonStatus - Holds daemon status information.
 *
 * Contains runtime information about daemon state that can be
 * exposed via HTTP status endpoint.
 */
class DaemonStatus
{
    /** @var float Daemon start time (microtime) */
    private float $startTime;

    /** @var int Memory usage in bytes */
    public int $memoryUsage = 0 {
        get {
            return $this->memoryUsage;
        }
    }

    /** @var float CPU usage percentage */
    public float $cpuUsage = 0.0 {
        get {
            return $this->cpuUsage;
        }
    }

    /** @var int Number of active regular workers */
    public int $workersRegular = 0 {
        get {
            return $this->workersRegular;
        }
    }

    /** @var int Number of active monopolistic workers */
    public int $workersMonopolistic = 0 {
        get {
            return $this->workersMonopolistic;
        }
    }

    /** @var int Maximum number of regular workers */
    public int $workersMaxRegular = 0 {
        get {
            return $this->workersMaxRegular;
        }
    }

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
     * Updates status information (memory, CPU, workers).
     */
    public function update(): void
    {
        $this->memoryUsage = memory_get_usage(true);
        // CPU usage calculation would require more complex logic
        // For now just set to 0
        $this->cpuUsage = 0.0;
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
}

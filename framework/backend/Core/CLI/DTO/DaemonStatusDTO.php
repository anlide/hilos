<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\DTO;

use Hilos\BaseDTO;

/**
 * DaemonStatusDTO - Data Transfer Object for daemon status.
 *
 * Contains daemon runtime information transmitted via HTTP API.
 */
class DaemonStatusDTO extends BaseDTO
{
    // Field name constants (camelCase for brevity)
    public const string uptime = 'uptime';
    public const string memory = 'memory';
    public const string cpu = 'cpu';
    public const string timestamp = 'timestamp';
    public const string workersRegular = 'workersRegular';
    public const string workersMonopolistic = 'workersMonopolistic';
    public const string workersMaxRegular = 'workersMaxRegular';

    /**
     * Creates daemon status DTO.
     *
     * @param int $uptime Uptime in seconds
     * @param int $memory Memory usage in bytes
     * @param float $cpu CPU usage percentage
     * @param int $timestamp Current Unix timestamp
     * @param int $workersRegular Number of regular workers
     * @param int $workersMonopolistic Number of monopolistic workers
     * @param int $workersMaxRegular Maximum regular workers capacity
     */
    public function __construct(
        public readonly int $uptime,
        public readonly int $memory,
        public readonly float $cpu,
        public readonly int $timestamp,
        public readonly int $workersRegular = 0,
        public readonly int $workersMonopolistic = 0,
        public readonly int $workersMaxRegular = 0,
    ) {
    }

    /**
     * Convert DTO to array.
     *
     * @return array<string, int|float> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::uptime => $this->uptime,
            self::memory => $this->memory,
            self::cpu => $this->cpu,
            self::timestamp => $this->timestamp,
            self::workersRegular => $this->workersRegular,
            self::workersMonopolistic => $this->workersMonopolistic,
            self::workersMaxRegular => $this->workersMaxRegular,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            uptime: $data[self::uptime] ?? 0,
            memory: $data[self::memory] ?? 0,
            cpu: $data[self::cpu] ?? 0.0,
            timestamp: $data[self::timestamp] ?? time(),
            workersRegular: $data[self::workersRegular] ?? 0,
            workersMonopolistic: $data[self::workersMonopolistic] ?? 0,
            workersMaxRegular: $data[self::workersMaxRegular] ?? 0,
        );
    }
}

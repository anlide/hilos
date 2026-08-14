<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * DaemonStatusDTO - Data Transfer Object for daemon status.
 *
 * Contains daemon runtime information transmitted via HTTP API.
 */
class DaemonStatusDTO extends BaseDTO
{
    // Field name constants
    public const string UPTIME = 'uptime';
    public const string MEMORY = 'memory';
    public const string CPU = 'cpu';
    public const string TIMESTAMP = 'timestamp';
    public const string WORKERS_REGULAR = 'workersRegular';
    public const string WORKERS_MONOPOLISTIC = 'workersMonopolistic';
    public const string WORKERS_MAX_REGULAR = 'workersMaxRegular';

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
            self::UPTIME => $this->uptime,
            self::MEMORY => $this->memory,
            self::CPU => $this->cpu,
            self::TIMESTAMP => $this->timestamp,
            self::WORKERS_REGULAR => $this->workersRegular,
            self::WORKERS_MONOPOLISTIC => $this->workersMonopolistic,
            self::WORKERS_MAX_REGULAR => $this->workersMaxRegular,
        ];
    }

    /**
     * Create DTO from array.
     *
     * Every field is required, the timestamp included: read as the reader's own
     * `time()` it would date the answer by the moment it was parsed, and a
     * status read from a daemon that stopped answering would look current.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload misses a field of the daemon status
     */
    public static function fromArray(array $data): static
    {
        return new static(
            uptime: self::requireInt($data, self::UPTIME),
            memory: self::requireInt($data, self::MEMORY),
            cpu: self::requireFloat($data, self::CPU),
            timestamp: self::requireInt($data, self::TIMESTAMP),
            workersRegular: self::requireInt($data, self::WORKERS_REGULAR),
            workersMonopolistic: self::requireInt($data, self::WORKERS_MONOPOLISTIC),
            workersMaxRegular: self::requireInt($data, self::WORKERS_MAX_REGULAR),
        );
    }
}

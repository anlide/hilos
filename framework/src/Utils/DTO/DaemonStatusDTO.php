<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO;

/**
 * DaemonStatusDTO - Data Transfer Object for daemon status
 *
 * Contains daemon runtime information transmitted via HTTP API.
 */
class DaemonStatusDTO extends BaseDTO
{
    public function __construct(
        public readonly bool $running,
        public readonly int $pid,
        public readonly int $uptime,
        public readonly int $memory,
        public readonly float $cpu,
        public readonly int $timestamp,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        return [
            'running' => $this->running,
            'pid' => $this->pid,
            'uptime' => $this->uptime,
            'memory' => $this->memory,
            'cpu' => $this->cpu,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            running: $data['running'] ?? false,
            pid: $data['pid'] ?? 0,
            uptime: $data['uptime'] ?? 0,
            memory: $data['memory'] ?? 0,
            cpu: $data['cpu'] ?? 0.0,
            timestamp: $data['timestamp'] ?? time(),
        );
    }
}


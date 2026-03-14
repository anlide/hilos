<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;
use Hilos\Guardian\Enums\TaskStatus;

final class TaskResult extends BaseDTO
{
    /**
     * Creates task result DTO.
     *
     * @param string $taskId Task identifier
     * @param TaskStatus $status Task status enum
     * @param array<string, mixed> $payload Result payload data
     * @param ?string $error Error message (null if success)
     */
    public function __construct(
        public readonly string $taskId,
        public readonly TaskStatus $status,
        public readonly array $payload = [],
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with taskId, status, payload, error keys
     */
    public function toArray(): array
    {
        return [
            'taskId' => $this->taskId,
            'status' => $this->status->value,
            'payload' => $this->payload,
            'error' => $this->error,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            taskId: (string) ($data['taskId'] ?? ''),
            status: TaskStatus::from((string) ($data['status'] ?? TaskStatus::FAILED->value)),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;
use Hilos\Guardian\Enums\TaskStatus;

final class TaskResult extends BaseDTO
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $taskId,
        public readonly TaskStatus $status,
        public readonly array $payload = [],
        public readonly ?string $error = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'taskId' => $this->taskId,
            'status' => $this->status->value,
            'payload' => $this->payload,
            'error' => $this->error,
        ];
    }

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

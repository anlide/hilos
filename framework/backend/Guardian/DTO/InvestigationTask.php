<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;
use Hilos\Guardian\Enums\TaskStatus;

final class InvestigationTask extends BaseDTO
{
    /**
     * Creates investigation task instance.
     *
     * @param string $id Task identifier
     * @param string $goal Task goal/description
     * @param array<string, mixed> $input Task input data
     * @param TaskStatus $status Task status (default: PENDING)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $goal,
        public readonly array $input = [],
        public TaskStatus $status = TaskStatus::PENDING,
    ) {
    }

    /**
     * Converts task to array for transport.
     *
     * @return array<string, mixed> Task data with id, goal, input, status keys
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'goal' => $this->goal,
            'input' => $this->input,
            'status' => $this->status->value,
        ];
    }

    /**
     * Creates task from array.
     *
     * @param array<string, mixed> $data Source data (id, goal, input, status)
     * @return static Task instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            goal: (string) ($data['goal'] ?? ''),
            input: is_array($data['input'] ?? null) ? $data['input'] : [],
            status: TaskStatus::from((string) ($data['status'] ?? TaskStatus::PENDING->value)),
        );
    }
}

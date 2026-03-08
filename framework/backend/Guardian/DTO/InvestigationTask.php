<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;
use Hilos\Guardian\Enums\TaskStatus;

final class InvestigationTask extends BaseDTO
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public readonly string $id,
        public readonly string $goal,
        public readonly array $input = [],
        public TaskStatus $status = TaskStatus::PENDING,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'goal' => $this->goal,
            'input' => $this->input,
            'status' => $this->status->value,
        ];
    }

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

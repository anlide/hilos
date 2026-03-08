<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;
use Hilos\Guardian\Enums\ActionType;

final class GuardianActionRequest extends BaseDTO
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly ActionType $actionType,
        public readonly array $payload = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'actionType' => $this->actionType->value,
            'payload' => $this->payload,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            actionType: ActionType::from((string) ($data['actionType'] ?? ActionType::CREATE_REPORT->value)),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }
}

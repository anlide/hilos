<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;
use Hilos\Guardian\Enums\ActionType;

final class GuardianActionRequest extends BaseDTO
{
    /**
     * Creates guardian action request instance.
     *
     * @param ActionType $actionType Action type (CREATE_REPORT, etc.)
     * @param array<string, mixed> $payload Action payload data
     */
    public function __construct(
        public readonly ActionType $actionType,
        public readonly array $payload = [],
    ) {
    }

    /**
     * Converts request to array for transport.
     *
     * @return array<string, mixed> Request data with actionType, payload keys
     */
    public function toArray(): array
    {
        return [
            'actionType' => $this->actionType->value,
            'payload' => $this->payload,
        ];
    }

    /**
     * Creates request from array.
     *
     * @param array<string, mixed> $data Source data (actionType, payload)
     * @return static Request instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            actionType: ActionType::from((string) ($data['actionType'] ?? ActionType::CREATE_REPORT->value)),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }
}

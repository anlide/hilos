<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\User\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for user_update action payload.
 *
 * Requires id and name. Name must be non-empty (validated by handler).
 * Throws InvalidActionPayloadException when name is empty.
 */
class UserUpdateActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates user update action DTO.
     *
     * @param int $id User ID to update
     * @param string $name New display name
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return ChatSignalConstants::USER_UPDATE;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($inner) && isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        $name = isset($inner[ObjectUser::name]) && is_string($inner[ObjectUser::name]) ? trim($inner[ObjectUser::name]) : '';

        return new static(
            id: (int) ($inner[ObjectUser::id] ?? 0),
            name: $name,
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed> Data with id and name
     */
    public function toArray(): array
    {
        return [
            ObjectUser::id => $this->id,
            ObjectUser::name => $this->name,
        ];
    }
}

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
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }

    /**
     * Returns the action name this DTO represents.
     */
    public function getAction(): string
    {
        return ChatSignalConstants::USER_UPDATE;
    }

    /**
     * Creates DTO from payload array. Unwraps nested data key if present.
     *
     * @param array<string, mixed> $data Raw payload (may contain SignalPayloadConstants::FIELD_DATA wrapper)
     *
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $d = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($d) && isset($d[SignalPayloadConstants::FIELD_DATA]) && is_array($d[SignalPayloadConstants::FIELD_DATA])) {
            $d = $d[SignalPayloadConstants::FIELD_DATA];
        }

        $name = isset($d[ObjectUser::name]) && is_string($d[ObjectUser::name]) ? trim($d[ObjectUser::name]) : '';

        return new static(
            id: (int) ($d[ObjectUser::id] ?? 0),
            name: $name,
        );
    }

    /**
     * Converts DTO to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ObjectUser::id => $this->id,
            ObjectUser::name => $this->name,
        ];
    }
}

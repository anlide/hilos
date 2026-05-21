<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\HilosUser\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for the Hilos users rename action payload.
 */
final class HilosUserUpdateActionDTO extends ChatActionPayloadDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }

    public function getAction(): string
    {
        return ChatSignalConstants::HILOS_USER_UPDATE;
    }

    /**
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
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

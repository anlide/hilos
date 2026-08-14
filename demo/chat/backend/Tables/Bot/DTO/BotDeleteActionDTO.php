<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * DTO for bot_delete action payload.
 */
final class BotDeleteActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates bot delete action DTO.
     *
     * @param int $id Bot ID to delete
     */
    public function __construct(
        public readonly int $id,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return ChatSignalConstants::BOT_DELETE;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the payload names no bot to delete
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($inner) && isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(
            id: self::requireInt($inner, ObjectBot::id),
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed> Data with bot id key
     */
    public function toArray(): array
    {
        return [ObjectBot::id => $this->id];
    }
}

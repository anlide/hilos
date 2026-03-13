<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for bot_delete action payload.
 */
class BotDeleteActionDTO extends ChatActionPayloadDTO
{
    /**
     * @param int $id Bot ID to delete
     */
    public function __construct(
        public readonly int $id,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string
     */
    public function getAction(): string
    {
        return ChatSignalConstants::BOT_DELETE;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     *
     * @return self
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($inner) && isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(
            id: (int) ($inner[ObjectBot::id] ?? 0),
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [ObjectBot::id => $this->id];
    }
}

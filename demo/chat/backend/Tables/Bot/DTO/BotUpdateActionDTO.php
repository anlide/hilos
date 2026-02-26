<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for bot_update action payload.
 *
 * Only non-null fields are applied on update.
 */
class BotUpdateActionDTO extends ChatActionPayloadDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $style = null,
        public readonly ?string $topics = null,
        public readonly ?string $personality = null,
        public readonly ?bool $active = null,
    ) {
    }

    /**
     * Returns the action name this DTO represents.
     */
    public function getAction(): string
    {
        return ChatSignalConstants::BOT_UPDATE;
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

        return new static(
            id: (int) ($d[ObjectBot::id] ?? 0),
            name: isset($d[ObjectBot::name]) && is_string($d[ObjectBot::name]) ? trim($d[ObjectBot::name]) : null,
            description: array_key_exists(ObjectBot::description, $d) ? (is_string($d[ObjectBot::description]) ? $d[ObjectBot::description] : null) : null,
            style: array_key_exists(ObjectBot::style, $d) ? (is_string($d[ObjectBot::style]) ? $d[ObjectBot::style] : null) : null,
            topics: array_key_exists(ObjectBot::topics, $d) ? (is_string($d[ObjectBot::topics]) ? $d[ObjectBot::topics] : null) : null,
            personality: array_key_exists(ObjectBot::personality, $d) ? (is_string($d[ObjectBot::personality]) ? $d[ObjectBot::personality] : null) : null,
            active: isset($d[ObjectBot::active]) ? (bool) $d[ObjectBot::active] : null,
        );
    }

    /**
     * Converts DTO to array for serialization (only non-null fields).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            ObjectBot::id => $this->id,
            ObjectBot::name => $this->name,
            ObjectBot::description => $this->description,
            ObjectBot::style => $this->style,
            ObjectBot::topics => $this->topics,
            ObjectBot::personality => $this->personality,
            ObjectBot::active => $this->active,
        ], static fn($v) => $v !== null);
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for bot_create action payload.
 */
final class BotCreateActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates bot create action DTO.
     *
     * @param string $name Bot display name
     * @param ?string $description Bot description (optional)
     * @param ?string $style Writing style (optional)
     * @param ?string $topics Preferred topics (optional)
     * @param ?string $personality Personality traits (optional)
     * @param bool $active Whether bot is active
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $style = null,
        public readonly ?string $topics = null,
        public readonly ?string $personality = null,
        public readonly bool $active = true,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return ChatSignalConstants::BOT_CREATE;
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

        return new static(
            name: is_string($inner[ObjectBot::name] ?? null) ? trim($inner[ObjectBot::name]) : '',
            description: isset($inner[ObjectBot::description]) && is_string($inner[ObjectBot::description]) ? $inner[ObjectBot::description] : null,
            style: isset($inner[ObjectBot::style]) && is_string($inner[ObjectBot::style]) ? $inner[ObjectBot::style] : null,
            topics: isset($inner[ObjectBot::topics]) && is_string($inner[ObjectBot::topics]) ? $inner[ObjectBot::topics] : null,
            personality: isset($inner[ObjectBot::personality]) && is_string($inner[ObjectBot::personality]) ? $inner[ObjectBot::personality] : null,
            active: (bool) ($inner[ObjectBot::active] ?? true),
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed> Data with name, description, style, topics, personality, active keys
     */
    public function toArray(): array
    {
        return [
            ObjectBot::name => $this->name,
            ObjectBot::description => $this->description,
            ObjectBot::style => $this->style,
            ObjectBot::topics => $this->topics,
            ObjectBot::personality => $this->personality,
            ObjectBot::active => $this->active,
        ];
    }
}

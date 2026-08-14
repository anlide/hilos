<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * DTO for bot_update action payload.
 *
 * Only non-null fields are applied on update.
 */
final class BotUpdateActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates bot update action DTO.
     *
     * @param int $id Bot ID to update
     * @param ?string $name Display name (null = do not update)
     * @param ?string $description Description (null = do not update)
     * @param ?string $style Writing style (null = do not update)
     * @param ?string $topics Preferred topics (null = do not update)
     * @param ?string $personality Personality (null = do not update)
     * @param ?bool $active Active flag (null = do not update)
     */
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
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return ChatSignalConstants::BOT_UPDATE;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Instance
     * @throws InvalidFormatException When the payload names no bot, or fills a field with another type
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($inner) && isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        $name = self::optionalString($inner, ObjectBot::name);

        return new static(
            id: self::requireInt($inner, ObjectBot::id),
            name: $name !== null ? trim($name) : null,
            description: self::optionalString($inner, ObjectBot::description),
            style: self::optionalString($inner, ObjectBot::style),
            topics: self::optionalString($inner, ObjectBot::topics),
            personality: self::optionalString($inner, ObjectBot::personality),
            active: self::optionalBool($inner, ObjectBot::active),
        );
    }

    /**
     * Serializes to array (only non-null fields).
     *
     * @return array<string, mixed> Data with id and optionally name, description, style, topics, personality, active keys
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

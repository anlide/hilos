<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for bot_create action payload.
 */
class BotCreateActionDTO extends ChatActionPayloadDTO
{
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
     * Returns the action name this DTO represents.
     */
    public function getAction(): string
    {
        return ChatSignalConstants::BOT_CREATE;
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
            name: is_string($d[ObjectBot::name] ?? null) ? trim($d[ObjectBot::name]) : '',
            description: isset($d[ObjectBot::description]) && is_string($d[ObjectBot::description]) ? $d[ObjectBot::description] : null,
            style: isset($d[ObjectBot::style]) && is_string($d[ObjectBot::style]) ? $d[ObjectBot::style] : null,
            topics: isset($d[ObjectBot::topics]) && is_string($d[ObjectBot::topics]) ? $d[ObjectBot::topics] : null,
            personality: isset($d[ObjectBot::personality]) && is_string($d[ObjectBot::personality]) ? $d[ObjectBot::personality] : null,
            active: (bool) ($d[ObjectBot::active] ?? true),
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
            ObjectBot::name => $this->name,
            ObjectBot::description => $this->description,
            ObjectBot::style => $this->style,
            ObjectBot::topics => $this->topics,
            ObjectBot::personality => $this->personality,
            ObjectBot::active => $this->active,
        ];
    }
}

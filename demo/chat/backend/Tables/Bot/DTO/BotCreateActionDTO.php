<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;

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

    public function getAction(): string
    {
        return ChatSignalConstants::BOT_CREATE;
    }

    public static function fromArray(array $data): static
    {
        $d = $data['data'] ?? $data;
        if (is_array($d) && isset($d['data']) && is_array($d['data'])) {
            $d = $d['data'];
        }

        return new static(
            name: is_string($d['name'] ?? null) ? trim($d['name']) : '',
            description: isset($d['description']) && is_string($d['description']) ? $d['description'] : null,
            style: isset($d['style']) && is_string($d['style']) ? $d['style'] : null,
            topics: isset($d['topics']) && is_string($d['topics']) ? $d['topics'] : null,
            personality: isset($d['personality']) && is_string($d['personality']) ? $d['personality'] : null,
            active: (bool) ($d['active'] ?? true),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'style' => $this->style,
            'topics' => $this->topics,
            'personality' => $this->personality,
            'active' => $this->active,
        ];
    }
}

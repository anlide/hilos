<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;

/**
 * DTO for bot_update action payload.
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

    public function getAction(): string
    {
        return ChatSignalConstants::BOT_UPDATE;
    }

    public static function fromArray(array $data): static
    {
        $d = $data['data'] ?? $data;
        if (is_array($d) && isset($d['data']) && is_array($d['data'])) {
            $d = $d['data'];
        }

        return new static(
            id: (int) ($d['id'] ?? 0),
            name: isset($d['name']) && is_string($d['name']) ? trim($d['name']) : null,
            description: array_key_exists('description', $d) ? (is_string($d['description']) ? $d['description'] : null) : null,
            style: array_key_exists('style', $d) ? (is_string($d['style']) ? $d['style'] : null) : null,
            topics: array_key_exists('topics', $d) ? (is_string($d['topics']) ? $d['topics'] : null) : null,
            personality: array_key_exists('personality', $d) ? (is_string($d['personality']) ? $d['personality'] : null) : null,
            active: isset($d['active']) ? (bool) $d['active'] : null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'style' => $this->style,
            'topics' => $this->topics,
            'personality' => $this->personality,
            'active' => $this->active,
        ], static fn($v) => $v !== null);
    }
}

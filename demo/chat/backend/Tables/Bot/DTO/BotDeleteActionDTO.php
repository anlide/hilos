<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;

/**
 * DTO for bot_delete action payload.
 */
class BotDeleteActionDTO extends ChatActionPayloadDTO
{
    public function __construct(
        public readonly int $id,
    ) {
    }

    public function getAction(): string
    {
        return ChatSignalConstants::BOT_DELETE;
    }

    public static function fromArray(array $data): static
    {
        $d = $data['data'] ?? $data;
        if (is_array($d) && isset($d['data']) && is_array($d['data'])) {
            $d = $d['data'];
        }

        return new static(
            id: (int) ($d['id'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return ['id' => $this->id];
    }
}

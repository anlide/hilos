<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\User\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;

/**
 * DTO for user_update action payload.
 */
class UserUpdateActionDTO extends ChatActionPayloadDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $name = null,
    ) {
    }

    public function getAction(): string
    {
        return ChatSignalConstants::USER_UPDATE;
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
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
        ], static fn($v) => $v !== null);
    }
}

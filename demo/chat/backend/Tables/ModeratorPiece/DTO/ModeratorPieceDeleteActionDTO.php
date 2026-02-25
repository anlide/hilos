<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;

/**
 * DTO for moderator_piece_delete action payload.
 */
class ModeratorPieceDeleteActionDTO extends ChatActionPayloadDTO
{
    public function __construct(
        public readonly int $id,
    ) {
    }

    public function getAction(): string
    {
        return ChatSignalConstants::MODERATOR_PIECE_DELETE;
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

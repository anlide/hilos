<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;

/**
 * DTO for moderator_piece_update action payload.
 */
class ModeratorPieceUpdateActionDTO extends ChatActionPayloadDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $section = null,
        public readonly ?string $promptPiece = null,
    ) {
    }

    public function getAction(): string
    {
        return ChatSignalConstants::MODERATOR_PIECE_UPDATE;
    }

    public static function fromArray(array $data): static
    {
        $d = $data['data'] ?? $data;
        if (is_array($d) && isset($d['data']) && is_array($d['data'])) {
            $d = $d['data'];
        }

        return new static(
            id: (int) ($d['id'] ?? 0),
            section: isset($d['section']) && is_string($d['section']) ? trim($d['section']) : null,
            promptPiece: isset($d['promptPiece']) && is_string($d['promptPiece']) ? trim($d['promptPiece']) : null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'section' => $this->section,
            'promptPiece' => $this->promptPiece,
        ], static fn($v) => $v !== null);
    }
}

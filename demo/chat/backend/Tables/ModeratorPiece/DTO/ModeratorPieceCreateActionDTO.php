<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;

/**
 * DTO for moderator_piece_create action payload.
 */
class ModeratorPieceCreateActionDTO extends ChatActionPayloadDTO
{
    public function __construct(
        public readonly string $section,
        public readonly string $promptPiece,
    ) {
    }

    public function getAction(): string
    {
        return ChatSignalConstants::MODERATOR_PIECE_CREATE;
    }

    public static function fromArray(array $data): static
    {
        $d = $data['data'] ?? $data;
        if (is_array($d) && isset($d['data']) && is_array($d['data'])) {
            $d = $d['data'];
        }

        return new static(
            section: is_string($d['section'] ?? null) ? trim($d['section']) : '',
            promptPiece: is_string($d['promptPiece'] ?? null) ? trim($d['promptPiece']) : '',
        );
    }

    public function toArray(): array
    {
        return [
            'section' => $this->section,
            'promptPiece' => $this->promptPiece,
        ];
    }
}

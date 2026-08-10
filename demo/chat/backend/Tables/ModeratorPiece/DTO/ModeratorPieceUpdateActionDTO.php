<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for moderator_piece_update action payload.
 *
 * Only non-null fields are applied on update.
 */
final class ModeratorPieceUpdateActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates moderator piece update action DTO.
     *
     * @param int $id Piece ID to update
     * @param ?string $section Section (null = do not update)
     * @param ?string $promptPiece Prompt text (null = do not update)
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $section = null,
        public readonly ?string $promptPiece = null,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return ChatSignalConstants::MODERATOR_PIECE_UPDATE;
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
            id: (int) ($inner[ObjectModeratorPromptPiece::id] ?? 0),
            section: isset($inner[ObjectModeratorPromptPiece::section])
                && is_string($inner[ObjectModeratorPromptPiece::section])
                ? trim($inner[ObjectModeratorPromptPiece::section])
                : null,
            promptPiece: isset($inner[ObjectModeratorPromptPiece::promptPiece])
                && is_string($inner[ObjectModeratorPromptPiece::promptPiece])
                ? trim($inner[ObjectModeratorPromptPiece::promptPiece])
                : null,
        );
    }

    /**
     * Serializes to array (only non-null fields).
     *
     * @return array<string, mixed> Data with id, section, promptPiece (non-null only)
     */
    public function toArray(): array
    {
        return array_filter([
            ObjectModeratorPromptPiece::id => $this->id,
            ObjectModeratorPromptPiece::section => $this->section,
            ObjectModeratorPromptPiece::promptPiece => $this->promptPiece,
        ], static fn($v) => $v !== null);
    }
}

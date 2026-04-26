<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for moderator_piece_delete action payload.
 */
final class ModeratorPieceDeleteActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates moderator piece delete action DTO.
     *
     * @param int $id Piece ID to delete
     */
    public function __construct(
        public readonly int $id,
    ) {
    }

    /**
     * Action name this DTO represents.
     *
     * @return string Action name constant
     */
    public function getAction(): string
    {
        return ChatSignalConstants::MODERATOR_PIECE_DELETE;
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
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed> Data with piece id key
     */
    public function toArray(): array
    {
        return [ObjectModeratorPromptPiece::id => $this->id];
    }
}

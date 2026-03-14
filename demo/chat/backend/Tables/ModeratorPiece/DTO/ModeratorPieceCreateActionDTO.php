<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectPiece;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for moderator_piece_create action payload.
 */
class ModeratorPieceCreateActionDTO extends ChatActionPayloadDTO
{
    /**
     * @param string $section Section identifier (e.g. ObjectPiece::SECTION_MESSAGE_RULE)
     * @param string $promptPiece Prompt text for the piece
     */
    public function __construct(
        public readonly string $section,
        public readonly string $promptPiece,
    ) {
    }

    /**
     * Returns action name constant for this DTO.
     *
     * @return string Action name (MODERATOR_PIECE_CREATE)
     */
    public function getAction(): string
    {
        return ChatSignalConstants::MODERATOR_PIECE_CREATE;
    }

    /**
     * Creates instance from payload array.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     *
     * @return self
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($inner) && isset($inner[SignalPayloadConstants::FIELD_DATA]) && is_array($inner[SignalPayloadConstants::FIELD_DATA])) {
            $inner = $inner[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(
            section: is_string($inner[ObjectPiece::section] ?? null) ? trim($inner[ObjectPiece::section]) : '',
            promptPiece: is_string($inner[ObjectPiece::promptPiece] ?? null) ? trim($inner[ObjectPiece::promptPiece]) : '',
        );
    }

    /**
     * Serializes to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ObjectPiece::section => $this->section,
            ObjectPiece::promptPiece => $this->promptPiece,
        ];
    }
}

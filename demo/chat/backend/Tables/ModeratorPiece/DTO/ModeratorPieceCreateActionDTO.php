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
    public function __construct(
        public readonly string $section,
        public readonly string $promptPiece,
    ) {
    }

    /**
     * Returns the action name this DTO represents.
     */
    public function getAction(): string
    {
        return ChatSignalConstants::MODERATOR_PIECE_CREATE;
    }

    /**
     * Creates DTO from payload array. Unwraps nested data key if present.
     *
     * @param array<string, mixed> $data Raw payload (may contain SignalPayloadConstants::FIELD_DATA wrapper)
     *
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $d = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (is_array($d) && isset($d[SignalPayloadConstants::FIELD_DATA]) && is_array($d[SignalPayloadConstants::FIELD_DATA])) {
            $d = $d[SignalPayloadConstants::FIELD_DATA];
        }

        return new static(
            section: is_string($d[ObjectPiece::section] ?? null) ? trim($d[ObjectPiece::section]) : '',
            promptPiece: is_string($d[ObjectPiece::promptPiece] ?? null) ? trim($d[ObjectPiece::promptPiece]) : '',
        );
    }

    /**
     * Converts DTO to array for serialization.
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

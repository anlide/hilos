<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\ChatActionPayloadDTO;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectPiece;
use Hilos\Constants\SignalPayloadConstants;

/**
 * DTO for moderator_piece_update action payload.
 *
 * Only non-null fields are applied on update.
 */
class ModeratorPieceUpdateActionDTO extends ChatActionPayloadDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $section = null,
        public readonly ?string $promptPiece = null,
    ) {
    }

    /**
     * Returns the action name this DTO represents.
     */
    public function getAction(): string
    {
        return ChatSignalConstants::MODERATOR_PIECE_UPDATE;
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
            id: (int) ($d[ObjectPiece::id] ?? 0),
            section: isset($d[ObjectPiece::section]) && is_string($d[ObjectPiece::section]) ? trim($d[ObjectPiece::section]) : null,
            promptPiece: isset($d[ObjectPiece::promptPiece]) && is_string($d[ObjectPiece::promptPiece]) ? trim($d[ObjectPiece::promptPiece]) : null,
        );
    }

    /**
     * Converts DTO to array for serialization (only non-null fields).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            ObjectPiece::id => $this->id,
            ObjectPiece::section => $this->section,
            ObjectPiece::promptPiece => $this->promptPiece,
        ], static fn($v) => $v !== null);
    }
}

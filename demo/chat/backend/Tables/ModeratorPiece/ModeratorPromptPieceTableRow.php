<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece;

use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for moderator prompt pieces.
 */
final class ModeratorPromptPieceTableRow extends AbstractTableRow
{
    public const string id = ObjectModeratorPromptPiece::id;
    public const string section = ObjectModeratorPromptPiece::section;
    public const string promptPiece = ObjectModeratorPromptPiece::promptPiece;

    public function __construct(
        public int $id,
        public string $section,
        public string $promptPiece,
    ) {
    }

    /**
     * Returns the stable row id used by the moderator prompt pieces table.
     */
    public function getRowId(): int
    {
        return $this->id;
    }

    /**
     * Serializes the row to the moderator prompt pieces table payload shape.
     *
     * @return array{id: int, section: string, promptPiece: string}
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::section => $this->section,
            self::promptPiece => $this->promptPiece,
        ];
    }

    /**
     * Builds a moderator prompt piece row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     */
    public static function fromArray(array $data): static
    {
        return new self(
            id: (int) ($data[self::id] ?? 0),
            section: (string) ($data[self::section] ?? ''),
            promptPiece: (string) ($data[self::promptPiece] ?? ''),
        );
    }
}

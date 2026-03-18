<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Object\Collection\ModeratorPromptPieces as ObjectModeratorPromptPieces;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectPiece;
use Demo\Chat\Database\View\Collection\ModeratorPromptPieces as DbCollectionModeratorPromptPieces;
use Demo\Chat\Database\View\Item\ModeratorPromptPiece;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;
use RuntimeException;

/**
 * ModeratorPromptPiecesActions - write operations for ModeratorPromptPieces collection.
 *
 * @extends DbActions<ModeratorPromptPiece, ObjectModeratorPromptPieces>
 * @property-read DbCollectionModeratorPromptPieces $collection
 * @property-read ObjectModeratorPromptPieces $objectCollection
 */
final class ModeratorPromptPiecesActions extends DbActions
{
    /**
     * Creates a new moderator prompt piece and adds it to the collection.
     *
     * @param array<string, mixed> $data Piece fields (ObjectPiece::section, ObjectPiece::promptPiece)
     * @return ModeratorPromptPiece Created piece Db item
     * @throws HilosException On error (invalid data, database error, etc.)
     */
    public function create(array $data): ModeratorPromptPiece
    {
        $this->ensureCanWrite();

        $piece = ObjectPiece::create();
        $piece->section = $data[ObjectPiece::section];
        $piece->promptPiece = $data[ObjectPiece::promptPiece];
        $piece->sync();

        $this->addObjectToCollection($piece);

        return $this->createDbItemFromObject($piece);
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Object\Collection\ModeratorPromptPieces as ObjectModeratorPromptPieces;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Database\View\Collection\ModeratorPromptPieces as DbCollectionModeratorPromptPieces;
use Demo\Chat\Database\View\Item\ModeratorPromptPiece;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;

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
     * @param string $section Section identifier
     * @param string $promptPiece Prompt text
     * @return ModeratorPromptPiece Created piece Db item
     * @throws HilosException On error (invalid data, database error, etc.)
     */
    public function create(string $section, string $promptPiece): ModeratorPromptPiece
    {
        $this->ensureCanWrite();

        $piece = ObjectModeratorPromptPiece::create();
        $piece->section = $section;
        $piece->promptPiece = $promptPiece;
        $piece->sync();

        $this->addObjectToCollection($piece);

        return $this->createDbItemFromObject($piece);
    }
}

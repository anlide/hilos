<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Database\View\Item\ModeratorPromptPiece;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\HilosException;

/**
 * ModeratorPromptPieceActions - write operations for a single ModeratorPromptPiece item.
 *
 * @extends DbActions<ModeratorPromptPiece, ObjectModeratorPromptPiece>
 * @property-read ObjectModeratorPromptPiece $object
 */
final class ModeratorPromptPieceActions extends DbActions
{
    /**
     * Updates piece fields. Null parameters are left unchanged.
     *
     * @param ?string $section Section identifier
     * @param ?string $promptPiece Prompt text
     * @throws HilosException On error (invalid data, database error, etc.)
     * @throws ItemNotFoundForUpdateException If piece not found for update
     */
    public function update(?string $section = null, ?string $promptPiece = null): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Moderator prompt piece not found for update (id is null)');
        }

        if ($section !== null) {
            $this->object->section = $section;
        }
        if ($promptPiece !== null) {
            $this->object->promptPiece = $promptPiece;
        }

        $this->object->sync();
    }

    /**
     * Deletes the piece.
     *
     * @throws HilosException On error (database error, etc.)
     * @throws ItemNotFoundForDeleteException When piece not found for delete (id is null)
     * @throws ObjectCollectionNullException When object collection is null
     */
    public function delete(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Moderator prompt piece not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            throw new ObjectCollectionNullException('Object collection is null');
        }

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }
}

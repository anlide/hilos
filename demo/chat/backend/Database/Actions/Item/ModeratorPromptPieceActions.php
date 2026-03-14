<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectPiece;
use Demo\Chat\Database\View\Item\ModeratorPromptPiece;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\HilosException;

/**
 * ModeratorPromptPieceActions - write operations for a single ModeratorPromptPiece item.
 *
 * @extends DbActions<ModeratorPromptPiece, ObjectPiece>
 * @property-read ObjectPiece $object
 */
final class ModeratorPromptPieceActions extends DbActions
{
    /**
     * Updates piece fields. Only provided keys are updated.
     *
     * @param array<string, mixed> $data Fields to update (keys: ObjectPiece::section, ObjectPiece::promptPiece)
     * @throws HilosException On error (invalid data, database error, etc.)
     * @throws ItemNotFoundForUpdateException If piece not found for update
     */
    public function update(array $data): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Moderator prompt piece not found for update (id is null)');
        }

        if (array_key_exists(ObjectPiece::section, $data)) {
            $this->object->section = $data[ObjectPiece::section];
        }
        if (array_key_exists(ObjectPiece::promptPiece, $data)) {
            $this->object->promptPiece = $data[ObjectPiece::promptPiece];
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

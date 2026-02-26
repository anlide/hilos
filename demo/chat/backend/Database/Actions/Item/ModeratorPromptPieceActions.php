<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectPiece;
use Demo\Chat\Database\View\Item\ModeratorPromptPiece;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\HilosException;
use RuntimeException;

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
     *
     * @throws HilosException On error (invalid data, database error, etc.)
     */
    public function update(array $data): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new RuntimeException('Moderator prompt piece not found for update (id is null)');
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
     */
    public function delete(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new RuntimeException('Moderator prompt piece not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            throw new RuntimeException('Object collection is null');
        }

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }
}

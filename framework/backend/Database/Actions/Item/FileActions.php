<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Database\Object\Item\File as ObjectFile;
use Hilos\Database\View\Item\File;
use Hilos\HilosException;

/**
 * FileActions - write operations for a single File item of the files registry (HIL-336).
 *
 * @extends DbActions<File, ObjectFile>
 * @property-read ObjectFile $object
 */
final class FileActions extends DbActions
{
    /**
     * Marks this file linked by the project, so the janitor never takes it.
     *
     * Marking a file already bound writes nothing.
     *
     * @throws ItemNotFoundForUpdateException When the file is not persisted (id is null)
     * @throws HilosException On database or ownership error
     */
    public function markBound(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('File not found for markBound (id is null)');
        }

        if ($this->object->bound) {
            return;
        }

        $this->object->bound = true;
        $this->object->sync();
    }

    /**
     * Removes this file row and its in-memory object; the file on disk is the caller's.
     *
     * @throws ItemNotFoundForDeleteException When the file is not persisted (id is null)
     * @throws ForeignKeyConstraintException When a project row still references the file
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws HilosException On database, ownership, or collection failure
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('File not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\FileVariant as ObjectFileVariant;
use Hilos\Database\View\Item\FileVariant;
use Hilos\Files\Image\ImageVariant;
use Hilos\HilosException;

/**
 * @extends DbActions<FileVariant, ObjectFileVariant>
 * @property-read ObjectFileVariant $object
 */
final class FileVariantActions extends DbActions
{
    /**
     * Replaces the copy's registration after its settings changed; storage is the caller's.
     *
     * @param string $signature Fingerprint of the new rendering settings
     * @param string $storedName Bare name of the replacement in storage
     * @param string $mimeType Replacement's MIME type
     * @param int $size Replacement's byte count
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws ValidationException When a field is malformed or out of range
     * @throws HilosException When ownership or the database refuses the write
     */
    public function replace(string $signature, string $storedName, string $mimeType, int $size): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('File variant not found for replace (id is null)');
        }
        if (preg_match(ImageVariant::SIGNATURE_PATTERN, $signature) !== 1) {
            throw new ValidationException('File variant signature must be 8 lowercase hex characters');
        }
        if ($storedName === '' || in_array($storedName, ['.', '..'], true)
            || basename($storedName) !== $storedName || str_contains($storedName, "\0") || str_contains($storedName, '\\')) {
            throw new ValidationException('File variant stored_name must be a bare file name without a path');
        }
        if ($mimeType === '' || $size < 0) {
            throw new ValidationException('File variant needs a MIME type and a nonnegative size');
        }

        $previousSignature = $this->object->signature;
        $previousStoredName = $this->object->storedName;
        $previousMimeType = $this->object->mimeType;
        $previousSize = $this->object->size;
        $this->object->signature = $signature;
        $this->object->storedName = $storedName;
        $this->object->mimeType = $mimeType;
        $this->object->size = $size;
        try {
            $this->object->sync();
        } catch (DatabaseException $e) {
            // The caller drops the failed replacement's bytes; keep the cached row on the old copy.
            $this->object->signature = $previousSignature;
            $this->object->storedName = $previousStoredName;
            $this->object->mimeType = $previousMimeType;
            $this->object->size = $previousSize;
            throw $e;
        }
    }

    /**
     * Removes this row and its cached object; the files library removes the bytes afterwards.
     *
     * @throws ItemNotFoundForDeleteException When the row has no persisted id
     * @throws ObjectCollectionNullException When the item is detached from its collection
     * @throws HilosException When ownership, the database or collection refuses the removal
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('File variant not found for delete (id is null)');
        }
        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');
        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }
}

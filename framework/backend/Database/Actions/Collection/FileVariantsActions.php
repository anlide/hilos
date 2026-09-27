<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Object\Collection\FileVariants as ObjectFileVariants;
use Hilos\Database\View\Collection\FileVariants as DbCollectionFileVariants;
use Hilos\Database\View\Item\FileVariant;
use Hilos\Files\Image\ImageVariant;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * @extends DbActions<FileVariant, ObjectFileVariants>
 * @property-read DbCollectionFileVariants $collection
 * @property-read ObjectFileVariants $objectCollection
 */
class FileVariantsActions extends DbActions
{
    /**
     * Registers a copy after the files library has kept its bytes in storage.
     *
     * @param int $fileId Original registry file id
     * @param string $variant Declared variant name
     * @param string $signature Fingerprint of the rendering settings
     * @param string $storedName Bare name of the copy in storage
     * @param string $mimeType Copy's MIME type
     * @param int $size Copy's byte count
     * @return FileVariant Newly registered copy
     * @throws ValidationException When a field is malformed or out of range
     * @throws HilosException When ownership, the database or collection refuses the write
     */
    public function create(
        int $fileId,
        string $variant,
        string $signature,
        string $storedName,
        string $mimeType,
        int $size,
    ): FileVariant {
        $this->ensureCanCreateInSet((string)$fileId);

        if ($fileId <= 0) {
            throw new ValidationException('File variant file_id must be positive');
        }
        if (preg_match(ImageVariant::NAME_PATTERN, $variant) !== 1) {
            throw new ValidationException('File variant name must be 1..64 lowercase letters, digits or underscores');
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

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $copy = $objectClass::create();
        $copy->fileId = $fileId;
        $copy->variant = $variant;
        $copy->signature = $signature;
        $copy->storedName = $storedName;
        $copy->mimeType = $mimeType;
        $copy->size = $size;
        $copy->createdAt = TimeHelper::getSqlDateTime();
        $copy->sync();
        $this->addObjectToCollection($copy);

        return $this->createDbItemFromObject($copy);
    }
}

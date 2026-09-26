<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Object\Collection\Files as ObjectFiles;
use Hilos\Database\Object\Item\File as ObjectFile;
use Hilos\Database\View\Collection\Files as DbCollectionFiles;
use Hilos\Database\View\Item\File;
use Hilos\Files\ContentHash;
use Hilos\Files\FileVisibility;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Fs\FsDirectory;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * FilesActions - write operations for the Files collection (HIL-336).
 *
 * @extends DbActions<File, ObjectFiles>
 * @property-read DbCollectionFiles $collection
 * @property-read ObjectFiles $objectCollection
 */
final class FilesActions extends DbActions
{
    /**
     * Registers a published file, unbound, in the process of {@see AbstractFilesLibraryAgent}.
     *
     * The row is born unbound: the project links the file to its own record afterwards and
     * says so through the bind door, and until then the janitor may take it. The stored name
     * must be a bare name, because the janitor removes the file by it and
     * {@see FsDirectory::offsetGet()} does not clean what it is given. A second row with the
     * same stored name is refused by the unique key and that refusal is passed up as it is.
     *
     * @param string $storedName Name of the file in the files directory, without a path
     * @param string $filename Name the uploader gave the file
     * @param string $mimeType MIME type of the file
     * @param int $size Size of the file in bytes
     * @param string $contentHash Fingerprint of the file's content ({@see ContentHash})
     * @param int $ownerUserId Id of the person who owns the file
     * @param FileVisibility $visibility Who may be given the file
     * @return File Created file
     * @throws ValidationException When a field is empty, out of range or malformed, or the stored name carries a path
     * @throws HilosException On database or ownership error
     */
    public function create(
        string $storedName,
        string $filename,
        string $mimeType,
        int $size,
        string $contentHash,
        int $ownerUserId,
        FileVisibility $visibility,
    ): File {
        $this->ensureCanWrite(TruthSourceOperation::Add);

        if ($storedName === '' || basename($storedName) !== $storedName) {
            throw new ValidationException('File stored_name must be a bare file name without a path');
        }
        if ($filename === '') {
            throw new ValidationException('File filename must not be empty');
        }
        if ($mimeType === '') {
            throw new ValidationException('File mime_type must not be empty');
        }
        if ($size < 0) {
            throw new ValidationException('File size must not be negative');
        }
        if (!ContentHash::isValid($contentHash)) {
            throw new ValidationException('File content_hash must be 64 lowercase hex characters');
        }
        if ($ownerUserId <= 0) {
            throw new ValidationException('File owner_user_id must be a positive user id');
        }

        $file = ObjectFile::create();
        $file->storedName = $storedName;
        $file->filename = $filename;
        $file->mimeType = $mimeType;
        $file->size = $size;
        $file->contentHash = $contentHash;
        $file->ownerUserId = $ownerUserId;
        $file->visibility = $visibility->value;
        $file->bound = false;
        $file->createdAt = TimeHelper::getSqlDateTime();
        $file->sync();

        $this->addObjectToCollection($file);

        return $this->createDbItemFromObject($file);
    }
}

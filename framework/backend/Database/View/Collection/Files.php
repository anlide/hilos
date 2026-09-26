<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\FilesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Files as ObjectFiles;
use Hilos\Database\View\Item\File;
use Hilos\Files\ContentHash;
use Hilos\Files\Library\AbstractFilesLibraryAgent;

/**
 * Files - Db collection of the files registry (HIL-336).
 *
 * Read-facing API for the framework-owned hilos_file table, keyed by the row id. Its one
 * writer is {@see AbstractFilesLibraryAgent}; the create action lives on {@see FilesActions}.
 *
 * @extends DbCollection<File, ObjectFiles>
 * @method ObjectFiles|null getObjectCollection()
 * @method File|null current()
 * @method File|null first()
 * @method File|null last()
 * @method File|null offsetGet(mixed $offset)
 * @property-read FilesActions $actions Actions for write operations
 */
final class Files extends DbCollection
{
    public const string DB_ITEM_CLASS = File::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectFiles::class;

    /**
     * Lists files nobody has linked yet that were registered before a moment, oldest row first.
     *
     * @param string $cutoffSql Moment as an SQL datetime; only rows created strictly before it are listed
     * @param int $limit Maximum rows to load
     * @return list<File> Unbound file Db items, empty when none
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object or order direction is invalid
     * @throws DatabaseException When the lookup or lazy file load fails
     */
    public function findUnboundBefore(string $cutoffSql, int $limit): array
    {
        $result = [];
        foreach ($this->objectCollection->findUnboundBefore($cutoffSql, $limit) as $objectFile) {
            $item = $objectFile->id !== null ? $this->getItemForKey($objectFile->id) : null;
            if ($item === null) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Sums the sizes of every registered file, bound or not.
     *
     * @return int Bytes the registry's files take, 0 when it holds none
     * @throws DatabaseException When the sum query fails
     */
    public function totalSize(): int
    {
        return $this->objectCollection->totalSize();
    }

    /**
     * Tells whether a person already owns a registered file of this content.
     *
     * @param int $ownerUserId Person the file would belong to
     * @param string $contentHash Fingerprint of the content ({@see ContentHash})
     * @return bool Whether a row of that owner carries that fingerprint
     * @throws DatabaseException When the lookup query fails
     */
    public function hasOwnerContent(int $ownerUserId, string $contentHash): bool
    {
        return $this->objectCollection->hasOwnerContent($ownerUserId, $contentHash);
    }
}

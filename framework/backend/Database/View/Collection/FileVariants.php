<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\FileVariantsActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\FileVariants as ObjectFileVariants;
use Hilos\Database\View\Item\FileVariant;

/**
 * Image-copy rows keyed by their primary id; only the files library writes them.
 *
 * @extends DbCollection<FileVariant, ObjectFileVariants>
 * @method ObjectFileVariants|null getObjectCollection()
 * @method FileVariant|null offsetGet(mixed $offset)
 * @property-read FileVariantsActions $actions
 */
final class FileVariants extends DbCollection
{
    public const string DB_ITEM_CLASS = FileVariant::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectFileVariants::class;

    /**
     * @param int $fileId Original registry file id
     * @param string $variant Declared variant name
     * @return ?FileVariant Stored copy, or null before the first rendering
     * @throws DatabaseException When the lookup or lazy load fails
     * @throws InvalidArgumentException When the query or loaded object is invalid
     * @throws LogicException When the collection classes are not configured
     */
    public function findFor(int $fileId, string $variant): ?FileVariant
    {
        return $this->getItemForKey($this->objectCollection->findFor($fileId, $variant)?->id);
    }

    /**
     * A bounded infrastructure list for the files library's cleanup of one original.
     *
     * @param int $fileId Original registry file id
     * @return list<FileVariant> Its stored copies, empty when none
     * @throws DatabaseException When the lookup or lazy load fails
     * @throws InvalidArgumentException When the query or loaded object is invalid
     * @throws LogicException When the collection classes are not configured
     */
    public function forFile(int $fileId): array
    {
        $variants = [];
        foreach ($this->objectCollection->forFile($fileId) as $objectVariant) {
            $variant = $this->getItemForKey($objectVariant->id);
            if ($variant !== null) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }
}

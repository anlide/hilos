<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Collection\Files as EntityFiles;
use Hilos\Database\Entity\Item\File as EntityFile;
use Hilos\Database\Object\Item\File as ObjectFile;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;

/**
 * Files object collection - the rows of the files registry (HIL-336).
 *
 * @extends Objects<ObjectFile>
 * @method ObjectFile|null current()
 * @method ObjectFile|null first()
 * @method ObjectFile|null last()
 * @method ObjectFile|null get(int|string $key)
 * @method ObjectFile|null offsetGet(mixed $offset)
 */
final class Files extends Objects
{
    public const string OBJECT_CLASS = ObjectFile::class;
    public const string ENTITY_COLLECTION_CLASS = EntityFiles::class;
    public const string COLLECTION_KEY = HilosDbContext::files;

    /**
     * Lists files nobody has linked yet that were registered before a moment, oldest row first.
     *
     * @param string $cutoffSql Moment as an SQL datetime; only rows created strictly before it are listed
     * @param int $limit Maximum rows to load
     * @return list<ObjectFile> Unbound file objects, empty when none
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findUnboundBefore(string $cutoffSql, int $limit): array
    {
        return $this->hydrateAll(EntityFile::get(
            '`' . EntityFile::bound . '` = 0 AND `' . EntityFile::created_at . '` < ?',
            [$cutoffSql],
            [EntityFile::id => SqlSortDirection::ASC],
            $limit,
        ));
    }

    /**
     * Loads and caches every file of an entity query result.
     *
     * Typed on the base collection: the string-filter form of `Entity::get()` hands back a
     * plain {@see EntityCollection}.
     *
     * @param EntityCollection<EntityFile> $entities Entity query result to wrap
     * @return list<ObjectFile> File objects in answer order (empty when nothing matched)
     */
    private function hydrateAll(EntityCollection $entities): array
    {
        $result = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, ObjectFile::fromEntity($entity));
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
    }
}

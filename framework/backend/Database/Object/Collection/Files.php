<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Collection\Files as EntityFiles;
use Hilos\Database\Entity\Item\File as EntityFile;
use Hilos\Database\Object\Item\File as ObjectFile;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\SqlSortDirection;
use Hilos\Files\ContentHash;

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
     * Sums the sizes of every registered file, bound or not.
     *
     * @return int Bytes the registry's files take, 0 when it holds none
     * @throws DatabaseException When the sum query fails
     */
    public function totalSize(): int
    {
        $row = Database::sql(
            'SELECT COALESCE(SUM(`' . EntityFile::size . '`), 0) AS `total` FROM `' . EntityFile::_table . '`',
        )->firstRow();

        return $row === null ? 0 : (int)$row['total'];
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
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($ownerUserId));
        $params->add(SqlParam::string($contentHash));

        return Database::sql(
            'SELECT 1 FROM `' . EntityFile::_table . '` WHERE `' . EntityFile::owner_user_id . '` = ? AND `'
                . EntityFile::content_hash . '` = ? LIMIT 1',
            $params,
        )->firstRow() !== null;
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

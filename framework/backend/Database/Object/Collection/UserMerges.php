<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\UserMerges as EntityUserMerges;
use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\Database\Object\Objects;

/**
 * UserMerges - Object collection for the framework merge table, keyed by the folded account.
 *
 * Optional key reads use the local mirror and lazy loading; isMergedInDatabase() always
 * checks the database, even when the collection is already loaded whole.
 *
 * @extends Objects<ObjectUserMerge>
 * @method ObjectUserMerge|null current()
 * @method ObjectUserMerge|null first()
 * @method ObjectUserMerge|null last()
 * @method ObjectUserMerge|null get(int|string $key)
 * @method ObjectUserMerge|null offsetGet(mixed $offset)
 */
class UserMerges extends Objects
{
    public const string OBJECT_CLASS = ObjectUserMerge::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserMerges::class;
    public const string COLLECTION_KEY = HilosDbContext::userMerges;

    /**
     * Reads fresh presence without hydrating the local mirror, including tombstones whose survivor is null.
     * Inside PRIMARY a locking read also bypasses any consistent snapshot the caller opened earlier.
     *
     * @param int $userId Folded account whose own tombstone is checked
     * @return bool Whether the database currently holds that account's merge row
     * @throws DatabaseException When the query fails; failure is not reported as absence
     */
    public function isMergedInDatabase(int $userId): bool
    {
        $entity = static::entityClass();
        $index = $entity::getDatabase()->connectionIndex;
        $sql = 'SELECT `user_id` FROM ' . $entity::getSqlTableName() . ' WHERE `user_id` = ?';
        if (Database::runtimeTransactionLevel($index) !== null) {
            $sql .= ' LOCK IN SHARE MODE';
        }

        return Database::sql($sql, [$userId], index: $index)->firstRow() !== null;
    }
}

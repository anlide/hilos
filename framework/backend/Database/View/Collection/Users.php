<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\UsersActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Collection\Users as ObjectUsers;
use Hilos\Database\View\Item\User;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * Framework people, addressed by primary id.
 *
 * @extends DbCollection<User, ObjectUsers>
 * @method ObjectUsers|null getObjectCollection()
 * @method User|null current()
 * @method User|null first()
 * @method User|null last()
 * @method User offsetGet(mixed $offset)
 * @property-read UsersActions $actions Actions for write operations
 */
class Users extends DbCollection
{
    public const string DB_ITEM_CLASS = User::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUsers::class;

    /**
     * Lists every user, loading the collection in full first.
     *
     * The collection is lazy by key ({@see HilosDbContext::configure()}), so a whole-table
     * question needs an explicit full load before walking its rows.
     *
     * The result snapshots membership by user id and preserves the existing wrappers.
     *
     * @return static Separate manual collection, in the source's order
     * @throws DatabaseException When loading the user collection fails
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match the collection
     * @throws CollectionNotManualException When the result refuses manual membership
     * @throws ObjectGetIdStringNotImplementedException When a user has no usable primary key
     * @throws HilosException When the Object collection refuses a full load
     */
    public function listAll(): static
    {
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection !== null && !$objectCollection->isAllLoaded()) {
            $objectCollection->loadAllFromDB();
            $this->clearCache();
        }

        $users = static::initEmpty();
        foreach ($this as $user) {
            $users->add($user);
        }

        return $users;
    }

    /**
     * @param string $substring Literal name substring, matched with SQL collation
     * @return list<int> All matching current user ids, without loading user items
     * @throws DatabaseException When the scalar projection fails
     */
    public function idsMatchingName(string $substring): array
    {
        return $this->getObjectCollection()?->idsMatchingName($substring) ?? [];
    }

    /**
     * @param list<int> $ids User ids from the selected window; an empty list issues no SQL
     * @return array<int, string> Fresh names keyed by id, omitting absent users without changing cached items
     * @throws DatabaseException When the scalar projection fails
     */
    public function namesByIds(array $ids): array
    {
        return $this->getObjectCollection()?->namesByIds($ids) ?? [];
    }
}

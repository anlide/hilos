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

/**
 * Framework people, addressed by primary id.
 *
 * @extends DbCollection<User, ObjectUsers>
 * @method ObjectUsers|null getObjectCollection()
 * @method User|null current()
 * @method User|null first()
 * @method User|null last()
 * @method User|null offsetGet(mixed $offset)
 * @property-read UsersActions $actions Actions for write operations
 */
class Users extends DbCollection
{
    public const string DB_ITEM_CLASS = User::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUsers::class;

    /**
     * Lists every user, loading the collection in full first.
     *
     * The collection is lazy by key ({@see HilosDbContext::configure()}), so plain
     * iteration only sees the rows some earlier read happened to load. A whole-table
     * question - "who are the administrators" - needs all of them.
     *
     * @return list<User> Every user row, in collection order
     * @throws DatabaseException When loading the user collection fails
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match the collection
     */
    public function listAll(): array
    {
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection !== null && !$objectCollection->isAllLoaded()) {
            $objectCollection->loadAllFromDB();
            $this->clearCache();
        }

        $users = [];
        foreach ($this as $user) {
            $users[] = $user;
        }

        return $users;
    }
}

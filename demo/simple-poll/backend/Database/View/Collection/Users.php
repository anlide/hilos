<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Database\View\Collection;

use Demo\SimplePoll\Database\Actions\Collection\UsersActions;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Database\Object\Collection\Users as ObjectUsers;
use Demo\SimplePoll\Database\View\Item\User;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Users - Db collection of User items with additional filtering methods.
 *
 * @extends DbCollection<User, ObjectUsers>
 * @method ObjectUsers|null getObjectCollection()
 * @method User|null current()
 * @method User|null first()
 * @method User|null last()
 * @method User|null offsetGet(mixed $offset)
 * @property-read UsersActions $actions Actions for write operations
 */
final class Users extends DbCollection
{
    public const string DB_ITEM_CLASS = User::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUsers::class;

    /**
     * Lists every user, loading the collection in full first.
     *
     * The collection is lazy by key ({@see PollDbContext::configure()}), so plain
     * iteration only sees the rows some earlier read happened to load. A whole-table
     * question - "who are the administrators" - needs all of them, and a demo user
     * table is small enough to hold at once.
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

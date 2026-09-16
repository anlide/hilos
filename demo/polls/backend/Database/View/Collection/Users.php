<?php

declare(strict_types=1);

namespace Demo\Polls\Database\View\Collection;

use Demo\Polls\Database\Actions\Collection\UsersActions;
use Demo\Polls\Database\PollsDbContext;
use Demo\Polls\Database\Object\Collection\Users as ObjectUsers;
use Demo\Polls\Database\View\Item\User;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Collection\HilosUserBlockSource;

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
final class Users extends DbCollection implements HilosUserBlockSource
{
    public const string DB_ITEM_CLASS = User::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUsers::class;

    /**
     * Lists every user, loading the collection in full first.
     *
     * The collection is lazy by key ({@see PollsDbContext::configure()}), so plain
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

    /**
     * Reports the block flag of each requested user, reading each row by key.
     *
     * The collection is lazy by key ({@see PollsDbContext::configure()}), and a guard asking
     * about one person must not pull the whole table in to answer, so no row beyond the
     * requested ones is loaded.
     *
     * @param list<int> $userIds User ids to report on
     * @return array<int, bool> Block flag per requested id; every requested id present, a row that is gone answers false
     * @throws DatabaseException When lazy-loading a user row fails
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object type does not match the collection
     */
    public function blockedAmong(array $userIds): array
    {
        $blocked = [];
        foreach ($userIds as $userId) {
            $blocked[$userId] = $this[$userId]?->block ?? false;
        }

        return $blocked;
    }
}

<?php

namespace Demo\Chat\Hilos\Database\Collection;

use Demo\Chat\Database\Object\User as ObjectUser;
use Demo\Chat\Database\ObjectCollection\Users as ObjectUsers;
use Demo\Chat\Hilos\Database\Item\User;
use Demo\Chat\Hilos\Database\Actions\UsersActions;
use Hilos\Database\Object\Object_;
use Hilos\Exception\DatabaseException;
use Hilos\Hilos\Database\Collection\DbCollection;
use InvalidArgumentException;

/**
 * Users Db collection - collection of User items with additional filtering methods.
 *
 * @extends DbCollection<User>
 * @property-read UsersActions $actions Actions for write operations
 */
final class Users extends DbCollection
{
    protected function createIdea(Object_ &$object): User
    {
        if (!($object instanceof ObjectUser)) {
            throw new InvalidArgumentException("Object must be instance of ObjectUser");
        }
        return new User($object);
    }

    public function findBySession(string $sessionToken): ?User
    {
        if (empty($sessionToken)) {
            return null;
        }

        $objectCollection = $this->getObjectCollection();
        if (!($objectCollection instanceof ObjectUsers)) {
            throw new InvalidArgumentException("Expected ObjectUsers instance");
        }

        $objectUser = $objectCollection->findBySession($sessionToken);
        if ($objectUser === null || $objectUser->id === null) {
            return null;
        }

        return $this->getIdeaForKey($objectUser->id);
    }

    public function current(): ?User
    {
        $item = parent::current();
        return $item instanceof User ? $item : null;
    }

    public function first(): ?User
    {
        $item = parent::first();
        return $item instanceof User ? $item : null;
    }

    public function last(): ?User
    {
        $item = parent::last();
        return $item instanceof User ? $item : null;
    }

    public function offsetGet(mixed $offset): ?User
    {
        $item = parent::offsetGet($offset);
        return $item instanceof User ? $item : null;
    }
}

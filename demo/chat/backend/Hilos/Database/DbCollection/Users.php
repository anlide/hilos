<?php

namespace Demo\Chat\Hilos\Database\DbCollection;

use Demo\Chat\Hilos\Database\Db\User;
use Demo\Chat\Hilos\Database\DbActions\UsersActions;
use Demo\Chat\Database\Object\User as ObjectUser;
use Demo\Chat\Database\ObjectCollection\Users as ObjectUsers;
use Hilos\Hilos\Database\DbCollection;
use Hilos\Database\Object\Object_;
use Hilos\Exception\DatabaseException;
use InvalidArgumentException;

/**
 * Users Db collection - collection of User items with additional filtering methods.
 *
 * @extends DbCollection<User>
 * @property-read UsersActions $actions Actions for write operations
 */
final class Users extends DbCollection
{
    /**
     * Create User instance from Object.
     *
     * @param Object_ $object Object instance (reference)
     * @return User
     */
    protected function createIdea(Object_ &$object): User
    {
        if (!($object instanceof ObjectUser)) {
            throw new InvalidArgumentException("Object must be instance of ObjectUser");
        }
        return new User($object);
    }

    /**
     * Find user by session token
     * Returns User (read-only) or null
     *
     * @param string $sessionToken Session token (32 hex characters)
     * @return ?User User item or null if not found
     * @throws DatabaseException
     */
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

    /**
     * Get current User item
     *
     * @return ?User Current User item or null if invalid position
     */
    public function current(): ?User
    {
        $item = parent::current();
        return $item instanceof User ? $item : null;
    }

    /**
     * Get first User item
     *
     * @return ?User First User item or null if collection is empty
     */
    public function first(): ?User
    {
        $item = parent::first();
        return $item instanceof User ? $item : null;
    }

    /**
     * Get last User item
     *
     * @return ?User Last User item or null if collection is empty
     */
    public function last(): ?User
    {
        $item = parent::last();
        return $item instanceof User ? $item : null;
    }

    /**
     * Get User item by offset
     *
     * @param mixed $offset User ID
     * @return ?User User item or null if not found
     */
    public function offsetGet(mixed $offset): ?User
    {
        $item = parent::offsetGet($offset);
        return $item instanceof User ? $item : null;
    }
}

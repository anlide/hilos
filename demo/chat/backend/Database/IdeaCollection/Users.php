<?php

namespace Demo\Chat\Database\IdeaCollection;

use Demo\Chat\Database\Idea\User as IdeaUser;
use Demo\Chat\Database\IdeaActions\UsersActions as IdeaUsersActions;
use Demo\Chat\Database\Object\User as ObjectUser;
use Demo\Chat\Database\ObjectCollection\Users as ObjectUsers;
use Hilos\Database\Idea\IdeaCollection;
use Hilos\Database\Object\Object_;
use Hilos\Exception\DatabaseException;
use InvalidArgumentException;

/**
 * Users Idea Collection
 * Collection of User ideas with additional filtering methods
 *
 * @extends IdeaCollection<IdeaUser>
 * @property-read IdeaUsersActions $actions Actions for write operations
 */
final class Users extends IdeaCollection
{
    // init() and initEmpty() are inherited from IdeaCollection
    // Override only if custom initialization logic is needed

    // getObjectCollection() is inherited from IdeaCollection
    // ObjectCollection is set via setObjectCollection() by Idea::setRepresent()

    /**
     * Create Idea instance from Object
     *
     * @param Object_ $object Object instance (reference)
     * @return IdeaUser
     */
    protected function createIdea(Object_ &$object): IdeaUser
    {
        if (!($object instanceof ObjectUser)) {
            throw new InvalidArgumentException("Object must be instance of ObjectUser");
        }
        return new IdeaUser($object);
    }

    /**
     * Find user by session token
     * Returns Idea\User (read-only) or null
     *
     * @param string $sessionToken Session token (32 hex characters)
     * @return ?IdeaUser User idea or null if not found
     * @throws DatabaseException
     */
    public function findBySession(string $sessionToken): ?IdeaUser
    {
        if (empty($sessionToken)) {
            return null;
        }

        // Delegate to ObjectCollection
        $objectCollection = $this->getObjectCollection();
        if (!($objectCollection instanceof ObjectUsers)) {
            throw new InvalidArgumentException("Expected ObjectUsers instance");
        }

        $objectUser = $objectCollection->findBySession($sessionToken);

        if ($objectUser === null) {
            return null;
        }

        // Convert to Idea using Object
        $id = $objectUser->id;
        if ($id === null) {
            return null;
        }

        // Use getIdeaForKey which will create IdeaUser from ObjectUser
        return $this->getIdeaForKey($id);
    }

    // register() method moved to UsersActions

    /**
     * Get current User idea
     *
     * @return ?IdeaUser Current User idea or null if invalid position
     */
    public function current(): ?IdeaUser
    {
        $item = parent::current();
        return $item instanceof IdeaUser ? $item : null;
    }

    /**
     * Get first User idea
     *
     * @return ?IdeaUser First User idea or null if collection is empty
     */
    public function first(): ?IdeaUser
    {
        $item = parent::first();
        return $item instanceof IdeaUser ? $item : null;
    }

    /**
     * Get last User idea
     *
     * @return ?IdeaUser Last User idea or null if collection is empty
     */
    public function last(): ?IdeaUser
    {
        $item = parent::last();
        return $item instanceof IdeaUser ? $item : null;
    }

    /**
     * Get User idea by offset
     *
     * @param mixed $offset User ID
     * @return ?IdeaUser User idea or null if not found
     */
    public function offsetGet(mixed $offset): ?IdeaUser
    {
        $item = parent::offsetGet($offset);
        return $item instanceof IdeaUser ? $item : null;
    }

    /**
     * Convert to array with additional options
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        return parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation);
    }
}

<?php

namespace Demo\WebSocketTest\Database\IdeaCollection;

use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Demo\WebSocketTest\Database\ObjectCollection\Users as ObjectUsers;
use Hilos\Database\Idea\IdeaCollection;
use Hilos\Database\Object\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Users Idea Collection
 * Collection of User ideas with additional filtering methods
 */
final class Users extends IdeaCollection
{
    // init() and initEmpty() are inherited from IdeaCollection
    // Override only if custom initialization logic is needed

    /**
     * Get ObjectCollection from IdeaStorage
     * Returns null for manual collections
     * 
     * @return Objects|null ObjectCollection instance from IdeaStorage, or null for manual collections
     */
    protected function getObjectCollection(): ?Objects
    {
        $storage = Idea::$storage;
        if ($storage === null) {
            throw new RuntimeException("IdeaStorage not initialized");
        }
        return $storage->users;
    }

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
     * @return IdeaUser|null User idea or null if not found
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
            throw new RuntimeException("Expected ObjectUsers instance");
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

    /**
     * Register new user
     * Creates ObjectUser, adds it to ObjectCollection in IdeaStorage, and returns IdeaUser
     *
     * @param string $sessionToken Session token (32 hex characters)
     * @return IdeaUser Newly registered user idea
     * @throws DatabaseException If registration fails
     */
    public function register(string $sessionToken): IdeaUser
    {
        // Create ObjectUser through ObjectUser::register()
        $objectUser = ObjectUser::register($sessionToken);

        // Add to ObjectCollection in IdeaStorage
        $objectCollection = $this->getObjectCollection();
        if (!($objectCollection instanceof ObjectUsers)) {
            throw new RuntimeException("Expected ObjectUsers instance");
        }

        // Add ObjectUser to ObjectCollection
        if ($objectUser->id !== null) {
            $objectCollection[$objectUser->id] = $objectUser;
        }

        // Create and return IdeaUser
        return $this->createIdea($objectUser);
    }

    /**
     * Convert to array with additional options
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        return parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation);
    }
}

<?php

namespace Demo\WebSocketTest\Database\IdeaActions;

use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Demo\WebSocketTest\Database\IdeaCollection\Users as IdeaCollectionUsers;
use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Demo\WebSocketTest\Database\ObjectCollection\Users as ObjectUsers;
use Hilos\Database\Idea\IdeaActions;
use Hilos\Exception\DatabaseException;
use RuntimeException;

/**
 * Users Actions
 * Provides write operations for Users collection
 *
 * @property-read IdeaCollectionUsers $collection
 */
final class UsersActions extends IdeaActions
{
    /**
     * Register new user
     * Creates ObjectUser, adds it to ObjectCollection, and returns IdeaUser
     *
     * @param string $sessionToken Session token (32 hex characters)
     * @return IdeaUser Newly registered user idea
     * @throws DatabaseException If registration fails
     */
    public function register(string $sessionToken): IdeaUser
    {
        // Create ObjectUser through ObjectUser::register()
        $objectUser = ObjectUser::register($sessionToken);

        // Get ObjectCollection (returns reference to storage)
        /** @var ObjectUsers $objectCollection */
        $objectCollection = $this->collection->getObjectCollectionForActions();

        // Add ObjectUser to ObjectCollection (modifies storage directly via reference)
        if ($objectUser->id !== null) {
            $objectCollection[$objectUser->id] = $objectUser;
        }

        // Create and return IdeaUser using callback
        /** @var IdeaUser $ideaUser */
        $ideaUser = $this->createIdeaFromObject($objectUser);
        return $ideaUser;
    }
}

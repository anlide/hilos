<?php

namespace Demo\Chat\Database\IdeaActions;

use Demo\Chat\Database\Idea\User as IdeaUser;
use Demo\Chat\Database\IdeaCollection\Users as IdeaCollectionUsers;
use Demo\Chat\Database\Object\User as ObjectUser;
use Demo\Chat\Database\ObjectCollection\Users as ObjectUsers;
use Hilos\Database\Idea\IdeaActions;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Actions\IdeaActionsCallbackNotSetException;
use Hilos\Exception\Idea\Actions\IdeaActionsDuplicateIdException;
use Hilos\Exception\Idea\Actions\IdeaActionsObjectCollectionNullException;
use Hilos\Exception\Idea\Actions\IdeaActionsTableNameUndeterminedException;
use Hilos\Exception\Idea\Actions\IdeaActionsUnknownLazyStrategyException;
use Hilos\Exception\Idea\TruthSource\IdeaTruthSourceWriteNotAllowedException;
use RuntimeException;

/**
 * Users Actions
 * Provides write operations for Users collection
 *
 * @extends IdeaActions<IdeaUser>
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
     * @throws DatabaseException
     * @throws IdeaActionsCallbackNotSetException
     * @throws IdeaActionsObjectCollectionNullException
     * @throws IdeaActionsDuplicateIdException
     * @throws IdeaActionsTableNameUndeterminedException
     */
    public function register(string $sessionToken): IdeaUser
    {
        // Create ObjectUser through ObjectUser::register()
        $objectUser = ObjectUser::register($sessionToken);

        // Add to collection
        $this->addObjectToCollection($objectUser);

        // Return IdeaItem wrapper
        return $this->createIdeaFromObject($objectUser);
    }

    /**
     * Rename user by id.
     *
     * @param int $userId User id
     * @param string $newName New user name
     * @return ?array{userId:int,oldName:string,newName:string,lastActivity:string} Rename info or null if no change
     * @throws DatabaseException
     * @throws IdeaActionsObjectCollectionNullException
     * @throws IdeaActionsUnknownLazyStrategyException
     * @throws IdeaTruthSourceWriteNotAllowedException
     * @throws IdeaActionsTableNameUndeterminedException
     * @throws RuntimeException
     */
    public function rename(int $userId, string $newName): ?array
    {
        $this->ensureCanWrite();

        /** @var ObjectUsers $objectCollection */
        $objectCollection = $this->getObjectCollection();

        if (!isset($objectCollection[$userId])) {
            throw new RuntimeException("User not found for rename (userId={$userId})");
        }

        $oldName = $objectCollection[$userId]->name;
        if ($oldName === $newName) {
            throw new RuntimeException("New name is the same as old name for rename (userId={$userId})");
        }

        $objectCollection[$userId]->name = $newName;
        $objectCollection[$userId]->sync();

        $lastActivity = $objectCollection[$userId]->lastActivity;
        if ($lastActivity === null) {
            throw new RuntimeException("User lastActivity is null after rename (userId={$userId})");
        }

        return [
            'userId' => $userId,
            'oldName' => $oldName,
            'newName' => $newName,
            'lastActivity' => $lastActivity,
        ];
    }
}

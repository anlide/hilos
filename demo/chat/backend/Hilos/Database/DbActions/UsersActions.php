<?php

namespace Demo\Chat\Hilos\Database\DbActions;

use Demo\Chat\Hilos\Database\Db\User;
use Demo\Chat\Hilos\Database\DbCollection\Users as DbCollectionUsers;
use Demo\Chat\Database\Object\User as ObjectUser;
use Demo\Chat\Database\ObjectCollection\Users as ObjectUsers;
use Hilos\Hilos\Database\DbActions;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Hilos\Database\Actions\CallbackNotSetException;
use Hilos\Exception\Hilos\Database\Actions\DuplicateIdException;
use Hilos\Exception\Hilos\Database\Actions\ObjectCollectionNullException;
use Hilos\Exception\Hilos\Database\Actions\TableNameUndeterminedException;
use Hilos\Exception\Hilos\Database\Actions\UnknownLazyStrategyException;
use Hilos\Exception\Hilos\Database\TruthSource\WriteNotAllowedException;
use RuntimeException;

/**
 * Users Actions - provides write operations for Users collection.
 *
 * @extends DbActions<User>
 * @property-read DbCollectionUsers $collection
 */
final class UsersActions extends DbActions
{
    /**
     * Register new user.
     *
     * @param string $sessionToken Session token (32 hex characters)
     * @return User Newly registered user
     * @throws DatabaseException
     * @throws CallbackNotSetException
     * @throws ObjectCollectionNullException
     * @throws DuplicateIdException
     * @throws TableNameUndeterminedException
     */
    public function register(string $sessionToken): User
    {
        $objectUser = ObjectUser::register($sessionToken);
        $this->addObjectToCollection($objectUser);
        return $this->createIdeaFromObject($objectUser);
    }

    /**
     * Rename user by id.
     *
     * @param int $userId User id
     * @param string $newName New user name
     * @throws DatabaseException
     * @throws ObjectCollectionNullException
     * @throws UnknownLazyStrategyException
     * @throws WriteNotAllowedException
     * @throws TableNameUndeterminedException
     * @throws RuntimeException
     */
    public function rename(int $userId, string $newName): void
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

        if ($objectCollection[$userId]->lastActivity === null) {
            throw new RuntimeException("User lastActivity is null after rename (userId={$userId})");
        }
    }
}

<?php

namespace Demo\Chat\Hilos\Database\Actions;

use Demo\Chat\Database\Object\Collection\Users as ObjectUsers;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Hilos\Database\Collection\Users as DbCollectionUsers;
use Demo\Chat\Hilos\Database\Item\User;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\DatabaseException;
use Hilos\Hilos\Database\Actions\DbActions;
use Hilos\Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use RuntimeException;

/**
 * Users Actions - write operations for Users collection.
 *
 * @extends DbActions<User, ObjectUsers>
 * @method ObjectUsers getObjectCollection()
 * @property-read DbCollectionUsers $collection
 */
final class UsersActions extends DbActions
{
    /**
     * Registers a new user with the given session token.
     *
     * @param string $sessionToken Session token (32 hex characters)
     * @return User Registered user
     * @throws DatabaseException On invalid token format or if user with this token already exists
     * @throws CallbackNotSetException If collection callback is not set
     */
    public function register(string $sessionToken): User
    {
        if (strlen($sessionToken) !== 32 || !ctype_xdigit($sessionToken)) {
            throw new DatabaseException("Invalid session token format. Expected 32 hex characters.");
        }

        $objectCollection = $this->getObjectCollection();
        $existingUser = $objectCollection->findBySession($sessionToken);
        if ($existingUser !== null) {
            throw new DatabaseException("User with session token already exists");
        }

        $user = ObjectUser::create();
        $user->entity->name = 'User' . mt_rand(1000, 9999);
        $user->entity->session_token = $sessionToken;
        $user->entity->last_activity = date('Y-m-d H:i:s');

        try {
            $user->sync();
        } catch (DatabaseException $e) {
            throw new DatabaseException("Failed to register user: " . $e->getMessage(), $e->getCode(), $e);
        }

        $userId = $user->id;
        if ($userId !== null) {
            $objectCollection[$userId] = $user;
        }

        return $this->createIdeaFromObject($user);
    }

    /**
     * Renames user by ID.
     *
     * @param int $userId User ID
     * @param string $newName New display name
     * @throws RuntimeException If user not found or new name equals current name
     * @throws ObjectCollectionNullException If ObjectCollection is null
     * @throws UnknownLazyStrategyException If unknown lazy loading strategy
     * @throws WriteNotAllowedException If write is not allowed
     * @throws DatabaseException On database error
     */
    public function rename(int $userId, string $newName): void
    {
        $this->ensureCanWrite();

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

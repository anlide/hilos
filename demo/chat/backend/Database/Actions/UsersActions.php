<?php

namespace Demo\Chat\Database\Actions;

use Demo\Chat\Database\Object\Collection\Users as ObjectUsers;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Database\View\Collection\Users as DbCollectionUsers;
use Demo\Chat\Database\View\Item\User;
use Hilos\Database\Actions\DbActions;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;
use RuntimeException;

/**
 * Users Actions - write operations for Users collection.
 *
 * @extends DbActions<User, ObjectUsers>
 * @property-read DbCollectionUsers $collection
 * @property-read ObjectUsers $objectCollection
 */
final class UsersActions extends DbActions
{
    /**
     * Registers a new user with the given session token.
     *
     * @param string $sessionToken Session token (32 hex characters)
     * @return User Registered user
     * @throws HilosException On error (invalid token format, user already exists, database error, etc.)
     */
    public function register(string $sessionToken): User
    {
        $this->ensureCanWrite();

        if (strlen($sessionToken) !== 32 || !ctype_xdigit($sessionToken)) {
            throw new RuntimeException("Invalid session token format. Expected 32 hex characters.");
        }

        if ($this->objectCollection->findBySession($sessionToken) !== null) {
            throw new RuntimeException("User with session token already exists");
        }

        $user = ObjectUser::create();
        $user->name = 'User' . mt_rand(1000, 9999);
        $user->sessionToken = $sessionToken;
        $user->lastActivity = TimeHelper::getSqlDateTime();
        $user->sync();

        $this->addObjectToCollection($user);

        return $this->createDbItemFromObject($user);
    }

    /**
     * Renames user by ID.
     *
     * @param int $userId User ID
     * @param string $newName New display name
     * @throws HilosException On error (user not found, new name same as old name, database error, etc.)
     */
    public function rename(int $userId, string $newName): void
    {
        $this->ensureCanWrite();

        if (!isset($this->objectCollection[$userId])) {
            throw new RuntimeException("User not found for rename (userId={$userId})");
        }

        $oldName = $this->objectCollection[$userId]->name;
        if ($oldName === $newName) {
            throw new RuntimeException("New name is the same as old name for rename (userId={$userId})");
        }

        $this->objectCollection[$userId]->name = $newName;
        $this->objectCollection[$userId]->lastActivity = TimeHelper::getSqlDateTime();
        $this->objectCollection[$userId]->sync();
    }
}

<?php

namespace Demo\Chat\Hilos\Database\Actions;

use Demo\Chat\Database\Object\User as ObjectUser;
use Demo\Chat\Database\ObjectCollection\Users as ObjectUsers;
use Demo\Chat\Hilos\Database\Item\User;
use Demo\Chat\Hilos\Database\Collection\Users as DbCollectionUsers;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Hilos\Database\Actions\CallbackNotSetException;
use Hilos\Exception\Hilos\Database\Actions\DuplicateIdException;
use Hilos\Exception\Hilos\Database\Actions\ObjectCollectionNullException;
use Hilos\Exception\Hilos\Database\Actions\TableNameUndeterminedException;
use Hilos\Exception\Hilos\Database\Actions\UnknownLazyStrategyException;
use Hilos\Exception\Hilos\Database\TruthSource\WriteNotAllowedException;
use Hilos\Hilos\Database\Actions\DbActions;
use RuntimeException;

/**
 * Users Actions - provides write operations for Users collection.
 *
 * @extends DbActions<User>
 * @property-read DbCollectionUsers $collection
 */
final class UsersActions extends DbActions
{
    public function register(string $sessionToken): User
    {
        $objectUser = ObjectUser::register($sessionToken);
        $this->addObjectToCollection($objectUser);
        return $this->createIdeaFromObject($objectUser);
    }

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

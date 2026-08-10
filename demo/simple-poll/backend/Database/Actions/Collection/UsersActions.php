<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Database\Actions\Collection;

use Demo\SimplePoll\Database\Object\Collection\Users as ObjectUsers;
use Demo\SimplePoll\Database\Object\Item\User as ObjectUser;
use Demo\SimplePoll\Database\View\Collection\Users as DbCollectionUsers;
use Demo\SimplePoll\Database\View\Item\User;
use Hilos\Auth\Session\SessionToken;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * UsersActions - write operations for Users collection.
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
     * @param string $sessionToken Session token (32 lowercase hex characters)
     * @return User Registered user
     * @throws InvalidFormatException If the token is not a 32-character lowercase hex string
     * @throws DuplicateValueException If user with session token already exists
     * @throws HilosException On database error
     */
    public function register(string $sessionToken): User
    {
        $this->ensureCanWrite();

        SessionToken::ensureValid($sessionToken);

        if ($this->objectCollection->findBySession($sessionToken) !== null) {
            throw new DuplicateValueException("User with session token already exists");
        }

        $user = ObjectUser::create();
        $user->name = 'User' . RandomHelper::integer(1000, 9999);
        $user->sessionToken = $sessionToken;
        $user->lastActivity = TimeHelper::getSqlDateTime();
        $user->sync();

        // A test db-reset can truncate the users table under the still-running
        // monopolistic worker, so the auto-increment id this insert just minted
        // may still be held by a stale in-memory object from the previous DB
        // generation. The freshly inserted row is authoritative for this worker,
        // so evict any stale remnant at that id before adding: letting the
        // framework duplicate-id guard fire would crash the handshake worker and
        // drop the connecting client's live presence.
        unset($this->objectCollection[$user->getIdString()]);
        $this->addObjectToCollection($user);

        return $this->createDbItemFromObject($user);
    }
}

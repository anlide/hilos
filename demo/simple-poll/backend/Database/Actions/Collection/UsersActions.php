<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Database\Actions\Collection;

use Demo\SimplePoll\Database\Object\Collection\Users as ObjectUsers;
use Demo\SimplePoll\Database\Object\Item\User as ObjectUser;
use Demo\SimplePoll\Database\View\Collection\Users as DbCollectionUsers;
use Demo\SimplePoll\Database\View\Item\User;
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
     * Registers a fresh guest user.
     *
     * Takes no token since HIL-408: the session is the framework's row now, and
     * binding it to the user this mints is the caller's next step. A guest is
     * therefore identified by nothing but its id until that bind happens, which
     * is why nothing here can collide and no duplicate check is left.
     *
     * @return User Registered guest
     * @throws HilosException On database error
     */
    public function registerGuest(): User
    {
        $this->ensureCanWrite();

        $user = ObjectUser::create();
        $user->name = 'User' . RandomHelper::integer(1000, 9999);
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

<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Database\Actions\Collection;

use Demo\SimpleTodo\Database\Object\Collection\Users as ObjectUsers;
use Demo\SimpleTodo\Database\Object\Item\User as ObjectUser;
use Demo\SimpleTodo\Database\View\Collection\Users as DbCollectionUsers;
use Demo\SimpleTodo\Database\View\Item\User;
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
     * The numeric tail a generated display name ends in - four digits, so the users list
     * shows something a person can read out loud and two rows minted in the same second
     * still look different. Both registrations draw from the same range because it is the
     * same tail on the same kind of name, not two numbers that happen to agree.
     *
     * @var int Lowest suffix a generated display name can carry
     */
    private const int NAME_SUFFIX_MIN = 1000;

    /** @var int Highest suffix a generated display name can carry */
    private const int NAME_SUFFIX_MAX = 9999;

    /**
     * Registers a fresh guest user.
     *
     * Takes no token since HIL-407: the session is the framework's row now, and
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
        $user->name = 'User' . RandomHelper::integer(self::NAME_SUFFIX_MIN, self::NAME_SUFFIX_MAX);
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

    /**
     * Registers a fresh user that is already an administrator.
     *
     * Its own method rather than a flag on {@see self::registerGuest()}: the two are minted
     * for unrelated reasons - a visitor arriving and an operator claiming a browser - and the
     * guest registration goes away with the visitor, while this one stays. The name is
     * symmetric with the guest's for the same reason the guest has one at all: the users
     * list shows a name, and this row appears in it.
     *
     * The caller binds the session to what this returns; nothing here identifies the row.
     *
     * @return User Registered administrator
     * @throws HilosException On database error
     */
    public function registerAdmin(): User
    {
        $this->ensureCanWrite();

        $user = ObjectUser::create();
        $user->name = 'Admin' . RandomHelper::integer(self::NAME_SUFFIX_MIN, self::NAME_SUFFIX_MAX);
        $user->admin = true;
        $user->lastActivity = TimeHelper::getSqlDateTime();
        $user->sync();

        // A test db-reset can truncate the users table under the still-running monopolistic
        // worker, so the auto-increment id this insert just minted may still be held by a
        // stale in-memory object from the previous DB generation - the same collision
        // registerGuest() evicts, for the same reason.
        unset($this->objectCollection[$user->getIdString()]);
        $this->addObjectToCollection($user);

        return $this->createDbItemFromObject($user);
    }
}

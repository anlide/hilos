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
     * The numeric tail a generated display name ends in - four digits, so the users list
     * shows something a person can read out loud and two rows minted in the same second
     * still look different.
     *
     * @var int Lowest suffix a generated display name can carry
     */
    private const int NAME_SUFFIX_MIN = 1000;

    /** @var int Highest suffix a generated display name can carry */
    private const int NAME_SUFFIX_MAX = 9999;

    /**
     * Registers a fresh user that is already an administrator.
     *
     * The only way to a `user` row in this demo since HIL-611: a row here means an
     * account, and a visitor is not one. The flag is set by the mint rather than by a
     * grant behind it, because the admin pages open on a row that says admin, and on a
     * fresh installation no row does - the id to grant is exactly what nobody can look
     * up yet. The name is generated because the users list shows one.
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
        // stale in-memory object from the previous DB generation. The freshly inserted row is
        // authoritative for this worker, so evict any stale remnant at that id before adding:
        // letting the framework duplicate-id guard fire would crash the worker.
        unset($this->objectCollection[$user->getIdString()]);
        $this->addObjectToCollection($user);

        return $this->createDbItemFromObject($user);
    }
}

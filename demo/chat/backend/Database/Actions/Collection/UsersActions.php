<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Object\Collection\Users as ObjectUsers;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Database\View\Collection\Users as DbCollectionUsers;
use Demo\Chat\Database\View\Item\User;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;
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
     * Creates a durable user with a display name (email+password registration).
     *
     * Registration path (HIL-164): the user is created up front with the given
     * display name and no session token — the session→user binding is applied
     * separately through the auth seam, not carried on the user row. The name is
     * defaulted from the email local part by the caller and is editable later in
     * Profile.
     *
     * @param string $name Display name for the new user
     * @return User Created user
     * @throws HilosException On database error
     */
    public function createWithName(string $name): User
    {
        $this->ensureCanWrite();

        $user = ObjectUser::create();
        $user->name = $name;
        $user->lastActivity = TimeHelper::getSqlDateTime();
        $user->sync();

        $this->addObjectToCollection($user);

        return $this->createDbItemFromObject($user);
    }
}

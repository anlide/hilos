<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Object\Collection\Users as ObjectUsers;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Database\View\Collection\Users as DbCollectionUsers;
use Demo\Chat\Database\View\Item\User;
use Hilos\Core\Exception\EmptyValueException;
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
     * chosen by the caller — each registration road has its own source — and is
     * editable later in Profile.
     *
     * The name is trimmed here and an empty one is refused (HIL-573): a nameless
     * account is a defect wherever it comes from, and this is the one door every
     * road passes through. The length is deliberately NOT checked — the other
     * callers (email, phone, fixtures) have no fallback to fall back to, so a
     * refusal by length would break registrations that are otherwise sound.
     *
     * @param string $name Display name for the new user
     * @return User Created user
     * @throws EmptyValueException When the name is empty or blank
     * @throws HilosException On database error
     */
    public function createWithName(string $name): User
    {
        $this->ensureCanCreate();

        $displayName = trim($name);
        if ($displayName === '') {
            throw new EmptyValueException('User name cannot be empty');
        }

        $user = ObjectUser::create();
        $user->name = $displayName;
        $user->lastActivity = TimeHelper::getSqlDateTime();
        $user->sync();

        $this->addObjectToCollection($user);

        return $this->createDbItemFromObject($user);
    }
}

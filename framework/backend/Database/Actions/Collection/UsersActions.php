<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Database\Object\Collection\Users as ObjectUsers;
use Hilos\Database\View\Collection\Users as DbCollectionUsers;
use Hilos\Database\View\Item\User;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * UsersActions - write operations for Users collection.
 *
 * @extends DbActions<User, ObjectUsers>
 * @property-read DbCollectionUsers $collection
 * @property-read ObjectUsers $objectCollection
 */
class UsersActions extends DbActions
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
     * The caller binds a session to the new account; its display name is generated.
     *
     * @return User Registered administrator
     * @throws HilosException On database error
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function registerAdmin(): User
    {
        $this->ensureCanCreate();

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $user = $objectClass::create();
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

    /**
     * Creates an account carrying a display name.
     *
     * The name comes from registration or an OAuth provider and remains editable.
     *
     * The name is trimmed here and an empty one is refused: a nameless account is a
     * defect wherever it comes from, and this is the one door every road passes
     * through. The length is deliberately NOT checked - the callers upstream have
     * no fallback to fall back to, so a refusal by length would break registrations
     * that are otherwise sound.
     *
     * The caller binds the session to what this returns; nothing here identifies the row.
     *
     * @param string $name Display name for the new account
     * @param ?string $themePick Guest browser's theme choice, or null when they never picked
     * @return User Created user
     * @throws EmptyValueException When the name is empty or blank
     * @throws ValidationException When the theme choice is outside the theme catalog
     * @throws HilosException On database error
     */
    public function createWithName(string $name, ?string $themePick = null): User
    {
        $this->ensureCanCreate();

        $displayName = trim($name);
        if ($displayName === '') {
            throw new EmptyValueException('User name cannot be empty');
        }
        if ($themePick !== null && !in_array($themePick, ThemeSettingsCatalog::THEME_VALUES, true)) {
            throw new ValidationException(ThemeSettingsCatalog::THEME_VALUE_REFUSAL);
        }

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $user = $objectClass::create();
        $user->name = $displayName;
        $user->lastActivity = TimeHelper::getSqlDateTime();
        $user->themePick = $themePick;
        $user->sync();

        $this->addObjectToCollection($user);

        return $this->createDbItemFromObject($user);
    }
}

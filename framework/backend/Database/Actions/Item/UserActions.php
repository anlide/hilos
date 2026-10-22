<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use DateTimeZone;
use Hilos\Auth\Session\BrowserTimeZone;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\View\Item\User;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * UserActions - write operations for a single User item.
 *
 * @extends DbActions<User, ObjectUser>
 * @property-read ObjectUser $object
 */
class UserActions extends DbActions
{
    /**
     * Shortest display name a user may carry.
     *
     * Public because the frame is one, not two: a name arriving from an OAuth
     * provider is measured against the very frame the rename applies, instead of a
     * second copy of the numbers.
     */
    public const int NAME_MIN_LENGTH = 2;

    /** Longest display name a user may carry - the same frame, read from the other end. */
    public const int NAME_MAX_LENGTH = 64;

    /**
     * Renames current user item (only editable field). Validates and persists.
     *
     * @param string $newName New display name (trimmed)
     * @throws ItemNotFoundForUpdateException When user not found for rename (id is null)
     * @throws EmptyValueException When name is empty
     * @throws ValueTooShortException When name is too short
     * @throws ValueTooLongException When name exceeds maximum length
     * @throws HilosException On database error or other failure
     */
    public function rename(string $newName): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('User not found for rename (id is null)');
        }

        $name = trim($newName);
        if ($name === '') {
            throw new EmptyValueException('User name cannot be empty');
        }
        if (mb_strlen($name) < self::NAME_MIN_LENGTH) {
            throw new ValueTooShortException('User name is too short');
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new ValueTooLongException('User name exceeds maximum length of ' . self::NAME_MAX_LENGTH . ' characters');
        }

        if ($this->object->name === $name) {
            return;
        }

        $this->object->name = $name;
        $this->object->lastActivity = TimeHelper::getSqlDateTime();
        $this->object->sync();
    }

    /**
     * Sets the panel-admin flag on the current user item. Persists only on change.
     *
     * @param bool $admin New admin flag
     * @throws ItemNotFoundForUpdateException When the user is not found (id is null)
     * @throws HilosException On database error or other failure
     */
    public function setAdmin(bool $admin): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('User not found for setAdmin (id is null)');
        }

        if ($this->object->admin === $admin) {
            return;
        }

        $this->object->admin = $admin;
        $this->object->sync();
    }

    /**
     * Persists the account block flag only when it changes.
     *
     * @param bool $block Requested block flag
     * @throws ItemNotFoundForUpdateException When the user has no persisted id
     * @throws HilosException On database or truth-source failure
     */
    public function setBlock(bool $block): void
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('User not found for setBlock (id is null)');
        }
        if ($this->object->block === $block) {
            return;
        }

        $this->object->block = $block;
        $this->object->sync();
    }

    /**
     * Persists a choice or an explicit reset without changing activity time.
     *
     * @param ?string $themePick Light, dark or system; null to return to the site default
     * @throws ItemNotFoundForUpdateException When the user has no persisted id
     * @throws ValidationException When the choice is outside the theme catalog
     * @throws HilosException On database or truth-source failure
     */
    public function setThemePick(?string $themePick): void
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('User not found for setThemePick (id is null)');
        }
        if ($themePick !== null && !in_array($themePick, ThemeSettingsCatalog::THEME_VALUES, true)) {
            throw new ValidationException(ThemeSettingsCatalog::THEME_VALUE_REFUSAL);
        }
        if ($this->object->themePick === $themePick && $this->object->themePickInitialized) {
            return;
        }

        $this->object->themePick = $themePick;
        $this->object->themePickInitialized = true;
        $this->object->sync();
    }

    /**
     * Persists an exact IANA identifier or an automatic reset without changing activity time.
     *
     * @param ?string $timeZone Identifier including backward aliases, or null for Automatic
     * @throws ItemNotFoundForUpdateException When the user has no persisted id
     * @throws ValidationException When the identifier is outside the IANA catalog
     * @throws HilosException On database or truth-source failure
     */
    public function setTimeZone(?string $timeZone): void
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('User not found for setTimeZone (id is null)');
        }
        if ($timeZone !== null && !in_array($timeZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new ValidationException('Choose a valid IANA time zone or Automatic.');
        }
        if ($this->object->timeZone === $timeZone) {
            return;
        }

        $this->object->timeZone = $timeZone;
        $this->object->sync();
    }

    /**
     * Remembers the last usable IANA time zone a browser of this person named, without changing activity time (HIL-1582).
     *
     * Clearing is not a request this method takes: the memory leaves the row only by anonymization.
     *
     * @param string $lastBrowserTimeZone Exact IANA identifier including backward aliases
     * @throws ItemNotFoundForUpdateException When the user has no persisted id
     * @throws ValidationException When the name is not an identifier of the IANA catalog
     * @throws HilosException On database or truth-source failure
     */
    public function setLastBrowserTimeZone(string $lastBrowserTimeZone): void
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('User not found for setLastBrowserTimeZone (id is null)');
        }
        if (!BrowserTimeZone::isValid($lastBrowserTimeZone)) {
            throw new ValidationException('The browser time zone is not a valid IANA time zone.');
        }
        if ($this->object->lastBrowserTimeZone === $lastBrowserTimeZone) {
            return;
        }

        $this->object->lastBrowserTimeZone = $lastBrowserTimeZone;
        $this->object->sync();
    }

    /**
     * Deletes this person's row after the caller has removed its dependent rows.
     *
     * The row leaves this process's collection with it, as a second factor's does: a process that
     * erased a person must not go on answering the row a later frame asks it for (HIL-1410).
     *
     * @throws ItemNotFoundForUpdateException When the user is not persisted (id is null)
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the user cannot expose its id string
     * @throws HilosException On database error or other failure
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('User not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }
}

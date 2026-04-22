<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Database\View\Item\User;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * UserActions - write operations for a single User item.
 *
 * @extends DbActions<User, ObjectUser>
 * @property-read ObjectUser $object
 */
final class UserActions extends DbActions
{
    private const int NAME_MIN_LENGTH = 1;
    private const int NAME_MAX_LENGTH = 64;

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
}

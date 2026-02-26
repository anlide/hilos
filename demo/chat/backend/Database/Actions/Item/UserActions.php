<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Database\View\Item\User;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\HilosException;
use Hilos\Runtime\Exception\RuntimeException;
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
     *
     * @throws HilosException On error (user not found, invalid name, database error, etc.)
     */
    public function rename(string $newName): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new RuntimeException('User not found for rename (id is null)');
        }

        $name = trim($newName);
        if ($name === '') {
            throw new RuntimeException('User name cannot be empty');
        }
        if (mb_strlen($name) < self::NAME_MIN_LENGTH) {
            throw new RuntimeException('User name is too short');
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new RuntimeException('User name exceeds maximum length of ' . self::NAME_MAX_LENGTH . ' characters');
        }

        if ($this->object->name === $name) {
            return;
        }

        $this->object->name = $name;
        $this->object->lastActivity = TimeHelper::getSqlDateTime();
        $this->object->sync();
    }
}

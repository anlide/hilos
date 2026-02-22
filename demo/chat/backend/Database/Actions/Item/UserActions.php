<?php

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Database\View\Item\User;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;
use RuntimeException;

/**
 * UserActions - write operations for a single User item.
 *
 * @extends DbActions<User, ObjectUser>
 * @property-read ObjectUser $object
 */
final class UserActions extends DbActions
{
    /**
     * Renames current user item.
     *
     * @param string $newName New display name
     * @throws HilosException On error (empty id, new name same as old name, database error, etc.)
     */
    public function rename(string $newName): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new RuntimeException("User not found for rename (id is null)");
        }

        $oldName = $this->object->name;
        if ($oldName === $newName) {
            throw new RuntimeException("New name is the same as old name for rename (userId={$this->object->id})");
        }

        $this->object->name = $newName;
        $this->object->lastActivity = TimeHelper::getSqlDateTime();
        $this->object->sync();
    }
}

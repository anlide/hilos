<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Database\Entity\Item\Event;
use Demo\Chat\Database\Object\Item\UserRename as ObjectUserRename;
use Demo\Chat\Database\View\Item\UserRename;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Actions\Item\UserRenameActions as FrameworkUserRenameActions;
use Hilos\Database\Database;
use Hilos\HilosException;

/**
 * UserRenameActions - write operations for one row of chat's rename journal: the link to the event
 * of the room's feed, the one column chat adds to the framework's row (HIL-1196).
 *
 * @property-read ObjectUserRename $object
 */
final class UserRenameActions extends FrameworkUserRenameActions
{
    /**
     * Links an empty journal row to a live feed event. Repeated and late frames silently do nothing.
     * The local transaction restores the row's memory too if a foreign or unique key refuses the write.
     *
     * @param int $eventId Feed event written for the rename
     * @return bool Whether the link was written
     * @throws ItemNotFoundForUpdateException When the row is not persisted (id is null)
     * @throws HilosException On database or ownership error
     */
    public function linkEvent(int $eventId): bool
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Rename not found for linkEvent (id is null)');
        }

        if ($this->object->eventId !== null || Event::get([Event::id => $eventId])->first() === null) {
            return false;
        }

        Database::transactionStart();
        try {
            $this->object->eventId = $eventId;
            $this->object->sync();
            Database::transactionCommit();

            return true;
        } catch (HilosException $f) {
            Database::transactionRollbackSilent();
            throw $f;
        }
    }
}

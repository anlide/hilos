<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Database\Object\Item\UserRename as ObjectUserRename;
use Demo\Chat\Database\View\Item\UserRename;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Database\Actions\Item\UserRenameActions as FrameworkUserRenameActions;
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
     * Links this rename to the feed event that shows it.
     *
     * @param int $eventId Feed event written for the rename
     * @throws ItemNotFoundForUpdateException When the row is not persisted (id is null)
     * @throws HilosException On database or ownership error
     */
    public function linkEvent(int $eventId): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Rename not found for linkEvent (id is null)');
        }

        $this->object->eventId = $eventId;
        $this->object->sync();
    }
}

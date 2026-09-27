<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Object\Item\EventUserRename as ObjectEventUserRename;
use Demo\Chat\Database\Actions\Collection\EventUserRenamesActions;
use Demo\Chat\Database\Object\Collection\EventUserRenames as ObjectEventUserRenames;
use Demo\Chat\Database\View\Item\EventUserRename;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\HilosException;

/**
 * EventUserRenames - Db collection of rename event detail items.
 *
 * Array access uses event_id, so eventUserRenames[$eventId] returns the
 * rename detail for that parent event when it exists.
 *
 * @extends DbCollection<EventUserRename, ObjectEventUserRenames>
 * @method ObjectEventUserRenames|null getObjectCollection()
 * @method EventUserRename|null current()
 * @method EventUserRename|null first()
 * @method EventUserRename|null last()
 * @method EventUserRename|null offsetGet(mixed $offset)
 * @property-read EventUserRenamesActions $actions Actions for write operations
 */
final class EventUserRenames extends DbCollection
{
    public const string DB_ITEM_CLASS = EventUserRename::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectEventUserRenames::class;

    /**
     * @param int $userId Person whose records are requested
     * @return static Complete matching set, including rows not cached in this worker
     * @throws HilosException When the query or collection assembly fails
     */
    public function byTarget(int $userId): static
    {
        return $this->whereColumnIs(ObjectEventUserRename::targetUserId, $userId);
    }
}

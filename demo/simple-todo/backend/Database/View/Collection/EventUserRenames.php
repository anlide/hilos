<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Database\View\Collection;

use Demo\SimpleTodo\Database\Actions\Collection\EventUserRenamesActions;
use Demo\SimpleTodo\Database\Object\Collection\EventUserRenames as ObjectEventUserRenames;
use Demo\SimpleTodo\Database\View\Item\EventUserRename;
use Hilos\Database\View\Collection\DbCollection;

/**
 * EventUserRenames - Db collection of user-rename audit rows.
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
}

<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Database\View\Collection;

use Demo\SimplePoll\Database\Actions\Collection\UserRenamesActions;
use Demo\SimplePoll\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Demo\SimplePoll\Database\View\Item\UserRename;
use Hilos\Database\View\Collection\DbCollection;

/**
 * UserRenames - Db collection of user-rename audit rows.
 *
 * @extends DbCollection<UserRename, ObjectUserRenames>
 * @method ObjectUserRenames|null getObjectCollection()
 * @method UserRename|null current()
 * @method UserRename|null first()
 * @method UserRename|null last()
 * @method UserRename|null offsetGet(mixed $offset)
 * @property-read UserRenamesActions $actions Actions for write operations
 */
final class UserRenames extends DbCollection
{
    public const string DB_ITEM_CLASS = UserRename::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserRenames::class;
}

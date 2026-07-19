<?php

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\UsersActions;
use Demo\Chat\Database\Object\Collection\Users as ObjectUsers;
use Demo\Chat\Database\View\Item\User;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Users - Db collection of User items with additional filtering methods.
 *
 * @extends DbCollection<User, ObjectUsers>
 * @method ObjectUsers|null getObjectCollection()
 * @method User|null current()
 * @method User|null first()
 * @method User|null last()
 * @method User|null offsetGet(mixed $offset)
 * @property-read UsersActions $actions Actions for write operations
 */
final class Users extends DbCollection
{
    public const string DB_ITEM_CLASS = User::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUsers::class;
}

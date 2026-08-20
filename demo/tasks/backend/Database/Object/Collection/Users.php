<?php

namespace Demo\Tasks\Database\Object\Collection;

use Demo\Tasks\Database\Entity\Collection\Users as EntityUsers;
use Demo\Tasks\Database\Object\Item\User as ObjectUser;
use Demo\Tasks\Database\TasksDbContext;
use Hilos\Database\Object\Objects;

/**
 * Users - Object collection for tasks users.
 *
 * @extends Objects<ObjectUser>
 * @method ObjectUser|null current()
 * @method ObjectUser|null first()
 * @method ObjectUser|null last()
 * @method ObjectUser|null get(int|string $key)
 * @method ObjectUser|null offsetGet(mixed $offset)
 */
final class Users extends Objects
{
    public const string OBJECT_CLASS = ObjectUser::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUsers::class;
    public const string COLLECTION_KEY = TasksDbContext::users;
}

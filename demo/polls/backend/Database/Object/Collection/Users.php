<?php

namespace Demo\Polls\Database\Object\Collection;

use Demo\Polls\Database\Entity\Collection\Users as EntityUsers;
use Demo\Polls\Database\Object\Item\User as ObjectUser;
use Demo\Polls\Database\PollsDbContext;
use Hilos\Database\Object\Objects;

/**
 * Users - Object collection for poll users.
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
    public const string COLLECTION_KEY = PollsDbContext::users;
}

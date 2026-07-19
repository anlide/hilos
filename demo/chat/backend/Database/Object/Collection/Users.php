<?php

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Collection\Users as EntityUsers;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Object\Objects;

/**
 * Users - Object collection for chat users.
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
    public const string COLLECTION_KEY = ChatDbContext::users;
}

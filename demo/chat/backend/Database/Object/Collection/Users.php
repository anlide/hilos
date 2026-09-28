<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\Entity\Collection\Users as EntityUsers;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Object\Collection\Users as FrameworkUsers;

/**
 * Users - Object collection for chat users.
 *
 * @method ObjectUser|null current()
 * @method ObjectUser|null first()
 * @method ObjectUser|null last()
 * @method ObjectUser|null get(int|string $key)
 * @method ObjectUser|null offsetGet(mixed $offset)
 */
final class Users extends FrameworkUsers
{
    public const string OBJECT_CLASS = ObjectUser::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUsers::class;
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Entity\Collection\Users as FrameworkUsers;

/**
 * Users - Entity collection for users.
 *
 * @method EntityUser|null get(int|string|null $key)
 * @method EntityUser|null offsetGet(mixed $offset)
 */
final class Users extends FrameworkUsers
{
    public const string ENTITY_CLASS = EntityUser::class;
}

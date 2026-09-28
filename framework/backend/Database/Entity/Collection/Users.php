<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\User as EntityUser;
use IteratorAggregate;

/**
 * Users - Entity collection for users.
 *
 * @extends EntityCollection<EntityUser>
 * @implements IteratorAggregate<int|string, EntityUser>
 * @implements ArrayAccess<int|string, EntityUser>
 */
class Users extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUser::class;
}

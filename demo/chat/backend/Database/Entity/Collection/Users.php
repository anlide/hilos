<?php

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Entity\Collection\EntityCollection;

/**
 * Users Entity Collection
 *
 * @extends EntityCollection<EntityUser>
 * @implements \Iterator<int|string, EntityUser>
 * @implements \ArrayAccess<int|string, EntityUser>
 */
final class Users extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUser::class;
}

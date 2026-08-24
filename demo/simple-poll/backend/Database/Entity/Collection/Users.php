<?php

namespace Demo\SimplePoll\Database\Entity\Collection;

use ArrayAccess;
use Demo\SimplePoll\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * Users - Entity collection for users.
 *
 * @extends EntityCollection<EntityUser>
 * @implements IteratorAggregate<int|string, EntityUser>
 * @implements ArrayAccess<int|string, EntityUser>
 */
final class Users extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUser::class;
}

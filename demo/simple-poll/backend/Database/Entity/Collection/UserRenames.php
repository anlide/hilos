<?php

namespace Demo\SimplePoll\Database\Entity\Collection;

use ArrayAccess;
use Demo\SimplePoll\Database\Entity\Item\UserRename as EntityUserRename;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * UserRenames - Entity collection for user-rename audit rows.
 *
 * @extends EntityCollection<EntityUserRename>
 * @implements IteratorAggregate<int|string, EntityUserRename>
 * @implements ArrayAccess<int|string, EntityUserRename>
 */
final class UserRenames extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUserRename::class;
}

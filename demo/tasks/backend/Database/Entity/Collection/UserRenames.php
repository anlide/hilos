<?php

namespace Demo\Tasks\Database\Entity\Collection;

use ArrayAccess;
use Demo\Tasks\Database\Entity\Item\UserRename as EntityUserRename;
use Hilos\Database\Entity\Collection\EntityCollection;
use Iterator;

/**
 * UserRenames - Entity collection for user-rename audit rows.
 *
 * @extends EntityCollection<EntityUserRename>
 * @implements Iterator<int|string, EntityUserRename>
 * @implements ArrayAccess<int|string, EntityUserRename>
 */
final class UserRenames extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUserRename::class;
}

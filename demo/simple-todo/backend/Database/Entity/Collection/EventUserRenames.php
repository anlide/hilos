<?php

namespace Demo\SimpleTodo\Database\Entity\Collection;

use ArrayAccess;
use Demo\SimpleTodo\Database\Entity\Item\EventUserRename as EntityEventUserRename;
use Hilos\Database\Entity\Collection\EntityCollection;
use Iterator;

/**
 * EventUserRenames - Entity collection for user-rename audit rows.
 *
 * @extends EntityCollection<EntityEventUserRename>
 * @implements Iterator<int|string, EntityEventUserRename>
 * @implements ArrayAccess<int|string, EntityEventUserRename>
 */
final class EventUserRenames extends EntityCollection
{
    public const string ENTITY_CLASS = EntityEventUserRename::class;
}

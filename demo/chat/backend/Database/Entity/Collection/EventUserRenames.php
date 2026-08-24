<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Collection;

use ArrayAccess;
use Demo\Chat\Database\Entity\Item\EventUserRename as EntityEventUserRename;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * EventUserRenames - Entity collection for event_user_rename rows.
 *
 * @extends EntityCollection<EntityEventUserRename>
 * @implements IteratorAggregate<int|string, EntityEventUserRename>
 * @implements ArrayAccess<int|string, EntityEventUserRename>
 */
final class EventUserRenames extends EntityCollection
{
    public const string ENTITY_CLASS = EntityEventUserRename::class;
}

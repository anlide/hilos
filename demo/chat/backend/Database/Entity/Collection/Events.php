<?php

namespace Demo\Chat\Database\Entity\Collection;

use ArrayAccess;
use Demo\Chat\Database\Entity\Item\Event as EntityEvent;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * Events - Entity collection for events.
 *
 * @extends EntityCollection<EntityEvent>
 * @implements IteratorAggregate<int|string, EntityEvent>
 * @implements ArrayAccess<int|string, EntityEvent>
 */
final class Events extends EntityCollection
{
    public const string ENTITY_CLASS = EntityEvent::class;
}

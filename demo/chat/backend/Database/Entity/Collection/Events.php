<?php

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\Event as EntityEvent;
use Hilos\Database\Entity\Collection\EntityCollection;

/**
 * Events Entity Collection
 *
 * @extends EntityCollection<EntityEvent>
 * @implements \Iterator<int|string, EntityEvent>
 * @implements \ArrayAccess<int|string, EntityEvent>
 */
final class Events extends EntityCollection
{
    public const string ENTITY_CLASS = EntityEvent::class;
}

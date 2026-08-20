<?php

namespace Demo\SimpleTodo\Database\Entity\Collection;

use ArrayAccess;
use Demo\SimpleTodo\Database\Entity\Item\Guest as EntityGuest;
use Hilos\Database\Entity\Collection\EntityCollection;
use Iterator;

/**
 * Guests - Entity collection for guests.
 *
 * @extends EntityCollection<EntityGuest>
 * @implements Iterator<int|string, EntityGuest>
 * @implements ArrayAccess<int|string, EntityGuest>
 */
final class Guests extends EntityCollection
{
    public const string ENTITY_CLASS = EntityGuest::class;
}

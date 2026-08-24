<?php

namespace Demo\SimplePoll\Database\Entity\Collection;

use ArrayAccess;
use Demo\SimplePoll\Database\Entity\Item\Guest as EntityGuest;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * Guests - Entity collection for guests.
 *
 * @extends EntityCollection<EntityGuest>
 * @implements IteratorAggregate<int|string, EntityGuest>
 * @implements ArrayAccess<int|string, EntityGuest>
 */
final class Guests extends EntityCollection
{
    public const string ENTITY_CLASS = EntityGuest::class;
}

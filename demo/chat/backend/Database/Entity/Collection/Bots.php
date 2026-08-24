<?php

namespace Demo\Chat\Database\Entity\Collection;

use ArrayAccess;
use Demo\Chat\Database\Entity\Item\Bot as EntityBot;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * Bots - entity collection for Bot entities.
 *
 * @extends EntityCollection<EntityBot>
 * @implements IteratorAggregate<int|string, EntityBot>
 * @implements ArrayAccess<int|string, EntityBot>
 */
final class Bots extends EntityCollection
{
    public const string ENTITY_CLASS = EntityBot::class;
}

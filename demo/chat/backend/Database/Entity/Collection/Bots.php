<?php

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\Bot as EntityBot;
use Hilos\Database\Entity\Collection\EntityCollection;

/**
 * Bots Entity Collection
 *
 * @extends EntityCollection<EntityBot>
 * @implements \Iterator<int|string, EntityBot>
 * @implements \ArrayAccess<int|string, EntityBot>
 */
final class Bots extends EntityCollection
{
    public const string ENTITY_CLASS = EntityBot::class;
}

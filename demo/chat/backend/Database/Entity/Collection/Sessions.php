<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Collection;

use ArrayAccess;
use Demo\Chat\Database\Entity\Item\Session as EntitySession;
use Hilos\Database\Entity\Collection\EntityCollection;
use Iterator;

/**
 * Sessions - Entity collection for sessions.
 *
 * @extends EntityCollection<EntitySession>
 * @implements Iterator<int|string, EntitySession>
 * @implements ArrayAccess<int|string, EntitySession>
 */
final class Sessions extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySession::class;
}

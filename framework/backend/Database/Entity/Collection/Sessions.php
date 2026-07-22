<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Session as EntitySession;
use Iterator;

/**
 * Sessions - Entity collection for the framework hilos_session table.
 *
 * @extends EntityCollection<EntitySession>
 * @implements Iterator<int|string, EntitySession>
 * @implements ArrayAccess<int|string, EntitySession>
 */
final class Sessions extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySession::class;
}

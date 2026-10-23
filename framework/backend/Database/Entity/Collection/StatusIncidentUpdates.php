<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\StatusIncidentUpdate as EntityStatusIncidentUpdate;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityStatusIncidentUpdate>
 * @implements IteratorAggregate<int|string, EntityStatusIncidentUpdate>
 * @implements ArrayAccess<int|string, EntityStatusIncidentUpdate>
 */
class StatusIncidentUpdates extends EntityCollection
{
    public const string ENTITY_CLASS = EntityStatusIncidentUpdate::class;
}

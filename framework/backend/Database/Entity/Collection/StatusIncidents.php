<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityStatusIncident>
 * @implements IteratorAggregate<int|string, EntityStatusIncident>
 * @implements ArrayAccess<int|string, EntityStatusIncident>
 */
class StatusIncidents extends EntityCollection
{
    public const string ENTITY_CLASS = EntityStatusIncident::class;
}

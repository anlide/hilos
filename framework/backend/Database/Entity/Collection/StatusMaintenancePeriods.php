<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\StatusMaintenancePeriod as EntityStatusMaintenancePeriod;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityStatusMaintenancePeriod>
 * @implements IteratorAggregate<int|string, EntityStatusMaintenancePeriod>
 * @implements ArrayAccess<int|string, EntityStatusMaintenancePeriod>
 */
class StatusMaintenancePeriods extends EntityCollection
{
    public const string ENTITY_CLASS = EntityStatusMaintenancePeriod::class;
}

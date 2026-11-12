<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\StatusSilencePeriod as EntityStatusSilencePeriod;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityStatusSilencePeriod>
 * @implements IteratorAggregate<int|string, EntityStatusSilencePeriod>
 * @implements ArrayAccess<int|string, EntityStatusSilencePeriod>
 */
class StatusSilencePeriods extends EntityCollection
{
    public const string ENTITY_CLASS = EntityStatusSilencePeriod::class;
}

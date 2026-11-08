<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\ProbeValue as EntityProbeValue;

/** @extends EntityCollection<EntityProbeValue> */
class ProbeValues extends EntityCollection
{
    public const string ENTITY_CLASS = EntityProbeValue::class;
}

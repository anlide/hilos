<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\SecondFactor as EntitySecondFactor;

/**
 * SecondFactors entity collection.
 *
 * @extends EntityCollection<EntitySecondFactor>
 */
class SecondFactors extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySecondFactor::class;
}

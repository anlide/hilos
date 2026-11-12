<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\SecondFactorTrustRestriction as EntitySecondFactorTrustRestriction;

/**
 * SecondFactorTrustRestrictions entity collection.
 *
 * @extends EntityCollection<EntitySecondFactorTrustRestriction>
 */
class SecondFactorTrustRestrictions extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySecondFactorTrustRestriction::class;
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\SecondFactorTrust as EntitySecondFactorTrust;

/**
 * SecondFactorTrusts entity collection.
 *
 * @extends EntityCollection<EntitySecondFactorTrust>
 */
final class SecondFactorTrusts extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySecondFactorTrust::class;
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\SecondFactorReset as EntitySecondFactorReset;

/**
 * SecondFactorResets entity collection.
 *
 * @extends EntityCollection<EntitySecondFactorReset>
 */
final class SecondFactorResets extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySecondFactorReset::class;
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\SecondFactorSetting as EntitySecondFactorSetting;

/**
 * SecondFactorSettings entity collection.
 *
 * @extends EntityCollection<EntitySecondFactorSetting>
 */
final class SecondFactorSettings extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySecondFactorSetting::class;
}

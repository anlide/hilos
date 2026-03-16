<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Setting as EntitySetting;

/**
 * Settings entity collection.
 *
 * @extends EntityCollection<EntitySetting>
 */
final class Settings extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySetting::class;
}

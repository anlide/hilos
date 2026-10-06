<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\Locale as EntityLocale;

/** @extends EntityCollection<EntityLocale> */
class Locales extends EntityCollection
{
    public const string ENTITY_CLASS = EntityLocale::class;
}

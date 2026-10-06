<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\Country as EntityCountry;

/** @extends EntityCollection<EntityCountry> */
class Countries extends EntityCollection
{
    public const string ENTITY_CLASS = EntityCountry::class;
}

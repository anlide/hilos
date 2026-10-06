<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\CountryName as EntityCountryName;

/** @extends EntityCollection<EntityCountryName> */
class CountryNames extends EntityCollection
{
    public const string ENTITY_CLASS = EntityCountryName::class;
}

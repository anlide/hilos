<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\Language as EntityLanguage;

/** @extends EntityCollection<EntityLanguage> */
class Languages extends EntityCollection
{
    public const string ENTITY_CLASS = EntityLanguage::class;
}

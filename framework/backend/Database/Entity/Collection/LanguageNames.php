<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;

/** @extends EntityCollection<EntityLanguageName> */
class LanguageNames extends EntityCollection
{
    public const string ENTITY_CLASS = EntityLanguageName::class;
}

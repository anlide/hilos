<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\I18nReflow as EntityI18nReflow;

/** @extends EntityCollection<EntityI18nReflow> */
class I18nReflows extends EntityCollection
{
    public const string ENTITY_CLASS = EntityI18nReflow::class;
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\LegalAcceptance as EntityLegalAcceptance;

/** @extends EntityCollection<EntityLegalAcceptance> */
class LegalAcceptances extends EntityCollection
{
    public const string ENTITY_CLASS = EntityLegalAcceptance::class;
}

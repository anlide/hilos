<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\LegalAcceptanceExport as EntityLegalAcceptanceExport;

/**
 * LegalAcceptanceExports entity collection.
 *
 * @extends EntityCollection<EntityLegalAcceptanceExport>
 */
class LegalAcceptanceExports extends EntityCollection
{
    public const string ENTITY_CLASS = EntityLegalAcceptanceExport::class;
}

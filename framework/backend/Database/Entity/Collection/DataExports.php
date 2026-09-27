<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\DataExport as EntityDataExport;

/**
 * DataExports entity collection.
 *
 * @extends EntityCollection<EntityDataExport>
 */
class DataExports extends EntityCollection
{
    public const string ENTITY_CLASS = EntityDataExport::class;
}

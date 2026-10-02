<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AccessLogEntry as EntityAccessLogEntry;

/**
 * AccessLogEntries entity collection.
 *
 * @extends EntityCollection<EntityAccessLogEntry>
 */
class AccessLogEntries extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAccessLogEntry::class;
}

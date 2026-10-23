<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\ChangeLogEntry as EntityChangeLogEntry;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityChangeLogEntry>
 * @implements IteratorAggregate<int|string, EntityChangeLogEntry>
 * @implements ArrayAccess<int|string, EntityChangeLogEntry>
 */
class ChangeLogEntries extends EntityCollection
{
    public const string ENTITY_CLASS = EntityChangeLogEntry::class;
}

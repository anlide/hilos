<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\ChangeLogTable as EntityChangeLogTable;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityChangeLogTable>
 * @implements IteratorAggregate<int|string, EntityChangeLogTable>
 * @implements ArrayAccess<int|string, EntityChangeLogTable>
 */
class ChangeLogTables extends EntityCollection
{
    public const string ENTITY_CLASS = EntityChangeLogTable::class;
}

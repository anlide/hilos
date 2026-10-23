<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\ChangeLogTables as EntityChangeLogTables;
use Hilos\Database\Object\Item\ChangeLogTable as ObjectChangeLogTable;
use Hilos\Database\Object\Objects;

/**
 * @extends Objects<ObjectChangeLogTable>
 * @method ObjectChangeLogTable|null current()
 * @method ObjectChangeLogTable|null first()
 * @method ObjectChangeLogTable|null last()
 * @method ObjectChangeLogTable|null get(int|string $key)
 * @method ObjectChangeLogTable|null offsetGet(mixed $offset)
 */
class ChangeLogTables extends Objects
{
    public const string OBJECT_CLASS = ObjectChangeLogTable::class;
    public const string ENTITY_COLLECTION_CLASS = EntityChangeLogTables::class;
    public const string COLLECTION_KEY = HilosDbContext::changeLogTables;
    public const bool CACHE_ROWS = false;
}

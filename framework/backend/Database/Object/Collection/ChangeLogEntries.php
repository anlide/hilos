<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\ChangeLogEntries as EntityChangeLogEntries;
use Hilos\Database\Object\Item\ChangeLogEntry as ObjectChangeLogEntry;
use Hilos\Database\Object\Objects;

/**
 * @extends Objects<ObjectChangeLogEntry>
 * @method ObjectChangeLogEntry|null current()
 * @method ObjectChangeLogEntry|null first()
 * @method ObjectChangeLogEntry|null last()
 * @method ObjectChangeLogEntry|null get(int|string $key)
 * @method ObjectChangeLogEntry|null offsetGet(mixed $offset)
 */
class ChangeLogEntries extends Objects
{
    public const string OBJECT_CLASS = ObjectChangeLogEntry::class;
    public const string ENTITY_COLLECTION_CLASS = EntityChangeLogEntries::class;
    public const string COLLECTION_KEY = HilosDbContext::changeLogEntries;
    public const bool CACHE_ROWS = false;
}

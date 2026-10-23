<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\ChangeLogEntries as ObjectChangeLogEntries;
use Hilos\Database\View\Item\ChangeLogEntry;

/**
 * Addressed by global row id; set reads require a bounded query.
 *
 * @extends DbCollection<ChangeLogEntry, ObjectChangeLogEntries>
 * @method ObjectChangeLogEntries|null getObjectCollection()
 * @method ChangeLogEntry|null current()
 * @method ChangeLogEntry|null first()
 * @method ChangeLogEntry|null last()
 * @method ChangeLogEntry offsetGet(mixed $offset)
 */
class ChangeLogEntries extends DbCollection
{
    public const string DB_ITEM_CLASS = ChangeLogEntry::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogEntries::class;
}

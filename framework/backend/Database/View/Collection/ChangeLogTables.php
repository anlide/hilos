<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\ChangeLogTables as ObjectChangeLogTables;
use Hilos\Database\View\Item\ChangeLogTable;

/**
 * Addressed by global row id; set reads require a bounded query.
 *
 * @extends DbCollection<ChangeLogTable, ObjectChangeLogTables>
 * @method ObjectChangeLogTables|null getObjectCollection()
 * @method ChangeLogTable|null current()
 * @method ChangeLogTable|null first()
 * @method ChangeLogTable|null last()
 * @method ChangeLogTable offsetGet(mixed $offset)
 */
class ChangeLogTables extends DbCollection
{
    public const string DB_ITEM_CLASS = ChangeLogTable::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogTables::class;
}

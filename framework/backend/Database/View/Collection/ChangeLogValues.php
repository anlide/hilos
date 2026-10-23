<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\ChangeLogValues as ObjectChangeLogValues;
use Hilos\Database\View\Item\ChangeLogValue;

/**
 * Addressed by global row id; set reads require a bounded query.
 *
 * @extends DbCollection<ChangeLogValue, ObjectChangeLogValues>
 * @method ObjectChangeLogValues|null getObjectCollection()
 * @method ChangeLogValue|null current()
 * @method ChangeLogValue|null first()
 * @method ChangeLogValue|null last()
 * @method ChangeLogValue offsetGet(mixed $offset)
 */
class ChangeLogValues extends DbCollection
{
    public const string DB_ITEM_CLASS = ChangeLogValue::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogValues::class;
}

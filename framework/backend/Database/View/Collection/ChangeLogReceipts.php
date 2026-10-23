<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\ChangeLogReceipts as ObjectChangeLogReceipts;
use Hilos\Database\View\Item\ChangeLogReceipt;

/**
 * Addressed by global row id; set reads require a bounded query.
 *
 * @extends DbCollection<ChangeLogReceipt, ObjectChangeLogReceipts>
 * @method ObjectChangeLogReceipts|null getObjectCollection()
 * @method ChangeLogReceipt|null current()
 * @method ChangeLogReceipt|null first()
 * @method ChangeLogReceipt|null last()
 * @method ChangeLogReceipt offsetGet(mixed $offset)
 */
class ChangeLogReceipts extends DbCollection
{
    public const string DB_ITEM_CLASS = ChangeLogReceipt::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogReceipts::class;
}

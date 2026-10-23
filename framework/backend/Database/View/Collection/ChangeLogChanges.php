<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\ChangeLogChanges as ObjectChangeLogChanges;
use Hilos\Database\View\Item\ChangeLogChange;

/**
 * Addressed by global row id; set reads require a bounded query.
 *
 * @extends DbCollection<ChangeLogChange, ObjectChangeLogChanges>
 * @method ObjectChangeLogChanges|null getObjectCollection()
 * @method ChangeLogChange|null current()
 * @method ChangeLogChange|null first()
 * @method ChangeLogChange|null last()
 * @method ChangeLogChange offsetGet(mixed $offset)
 */
class ChangeLogChanges extends DbCollection
{
    public const string DB_ITEM_CLASS = ChangeLogChange::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogChanges::class;
}

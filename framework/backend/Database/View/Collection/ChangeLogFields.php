<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\ChangeLogFields as ObjectChangeLogFields;
use Hilos\Database\View\Item\ChangeLogField;

/**
 * Addressed by global row id; set reads require a bounded query.
 *
 * @extends DbCollection<ChangeLogField, ObjectChangeLogFields>
 * @method ObjectChangeLogFields|null getObjectCollection()
 * @method ChangeLogField|null current()
 * @method ChangeLogField|null first()
 * @method ChangeLogField|null last()
 * @method ChangeLogField offsetGet(mixed $offset)
 */
class ChangeLogFields extends DbCollection
{
    public const string DB_ITEM_CLASS = ChangeLogField::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogFields::class;
}

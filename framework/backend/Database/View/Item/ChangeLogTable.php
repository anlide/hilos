<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\ChangeLogTable as ObjectChangeLogTable;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectChangeLogTable>
 * @method __construct(ObjectChangeLogTable $object)
 *
 * @property-read ?int $id
 * @property-read string $name
 */
class ChangeLogTable extends DbItem
{
    /**
     * @param string $name Column name
     * @return mixed Scalar column value
     * @throws HilosException When the property does not exist or cannot be accessed
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectChangeLogTable::id => $this->_object->id,
            ObjectChangeLogTable::name => $this->_object->name,
            default => parent::__get($name),
        };
    }
}

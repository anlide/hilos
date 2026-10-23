<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\ChangeLogField as ObjectChangeLogField;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectChangeLogField>
 * @method __construct(ObjectChangeLogField $object)
 *
 * @property-read ?int $id
 * @property-read int $table_id
 * @property-read string $name
 */
class ChangeLogField extends DbItem
{
    /**
     * @param string $name Column name
     * @return mixed Scalar column value
     * @throws HilosException When the property does not exist or cannot be accessed
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectChangeLogField::id => $this->_object->id,
            ObjectChangeLogField::table_id => $this->_object->table_id,
            ObjectChangeLogField::name => $this->_object->name,
            default => parent::__get($name),
        };
    }
}

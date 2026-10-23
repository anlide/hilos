<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\ChangeLogValue as ObjectChangeLogValue;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectChangeLogValue>
 * @method __construct(ObjectChangeLogValue $object)
 *
 * @property-read ?int $id
 * @property-read string $created_at
 * @property-read int $change_id
 * @property-read ?string $old_value
 * @property-read ?string $new_value
 */
class ChangeLogValue extends DbItem
{
    /**
     * @param string $name Column name
     * @return mixed Scalar column value
     * @throws HilosException When the property does not exist or cannot be accessed
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectChangeLogValue::id => $this->_object->id,
            ObjectChangeLogValue::created_at => $this->_object->created_at,
            ObjectChangeLogValue::change_id => $this->_object->change_id,
            ObjectChangeLogValue::old_value => $this->_object->old_value,
            ObjectChangeLogValue::new_value => $this->_object->new_value,
            default => parent::__get($name),
        };
    }
}

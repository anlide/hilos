<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\ChangeLogChange as ObjectChangeLogChange;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectChangeLogChange>
 * @method __construct(ObjectChangeLogChange $object)
 *
 * @property-read ?int $id
 * @property-read string $created_at
 * @property-read int $log_id
 * @property-read int $field_id
 * @property-read string $kind
 * @property-read bool $old_present
 * @property-read bool $new_present
 * @property-read ?string $old_value
 * @property-read ?string $new_value
 */
class ChangeLogChange extends DbItem
{
    /**
     * @param string $name Column name
     * @return mixed Scalar column value
     * @throws HilosException When the property does not exist or cannot be accessed
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectChangeLogChange::id => $this->_object->id,
            ObjectChangeLogChange::created_at => $this->_object->created_at,
            ObjectChangeLogChange::log_id => $this->_object->log_id,
            ObjectChangeLogChange::field_id => $this->_object->field_id,
            ObjectChangeLogChange::kind => $this->_object->kind,
            ObjectChangeLogChange::old_present => $this->_object->old_present,
            ObjectChangeLogChange::new_present => $this->_object->new_present,
            ObjectChangeLogChange::old_value => $this->_object->old_value,
            ObjectChangeLogChange::new_value => $this->_object->new_value,
            default => parent::__get($name),
        };
    }
}

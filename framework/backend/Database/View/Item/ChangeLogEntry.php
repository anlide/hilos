<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Object\Item\ChangeLogEntry as ObjectChangeLogEntry;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectChangeLogEntry>
 * @method __construct(ObjectChangeLogEntry $object)
 *
 * @property-read ?int $id
 * @property-read string $created_at
 * @property-read ?int $receipt_id
 * @property-read int $table_id
 * @property-read string $record_key
 * @property-read ?string $record_key_hash
 * @property-read string $mutation_type
 */
class ChangeLogEntry extends DbItem
{
    /**
     * @param string $name Column name
     * @return mixed Scalar column value
     * @throws HilosException When the property does not exist or cannot be accessed
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectChangeLogEntry::id => $this->_object->id,
            ObjectChangeLogEntry::created_at => $this->_object->created_at,
            ObjectChangeLogEntry::receipt_id => $this->_object->receipt_id,
            ObjectChangeLogEntry::table_id => $this->_object->table_id,
            ObjectChangeLogEntry::record_key => $this->_object->record_key,
            ObjectChangeLogEntry::record_key_hash => $this->_object->record_key_hash,
            ObjectChangeLogEntry::mutation_type => $this->_object->mutation_type,
            default => parent::__get($name),
        };
    }
}

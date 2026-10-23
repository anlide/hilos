<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ChangeLogEntry as EntityChangeLogEntry;
use Hilos\Database\Object\Collection\ChangeLogEntries as ObjectChangeLogEntries;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/**
 * @extends Object_<EntityChangeLogEntry>
 *
 * @property-read ?int $id
 * @property string $created_at
 * @property ?int $receipt_id
 * @property int $table_id
 * @property string $record_key
 * @property ?string $record_key_hash
 * @property string $mutation_type
 */
class ChangeLogEntry extends Object_
{
    public const string ENTITY_CLASS = EntityChangeLogEntry::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogEntries::class;

    public const string id = EntityChangeLogEntry::id;
    public const string created_at = EntityChangeLogEntry::created_at;
    public const string receipt_id = EntityChangeLogEntry::receipt_id;
    public const string table_id = EntityChangeLogEntry::table_id;
    public const string record_key = EntityChangeLogEntry::record_key;
    public const string record_key_hash = EntityChangeLogEntry::record_key_hash;
    public const string mutation_type = EntityChangeLogEntry::mutation_type;

    /**
     * @return string Global auto-increment id, independent of the physical partition key
     * @throws ObjectGetIdStringNotImplementedException When an unsaved row has no id
     */
    public function getIdString(): string
    {
        return (string)($this->entity->id
            ?? throw new ObjectGetIdStringNotImplementedException('A change log row has no id'));
    }

    /**
     * @param string $property Column name
     * @return mixed Scalar column value
     * @throws DatabaseException When the property does not exist or cannot be accessed
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::created_at => $this->entity->created_at,
            self::receipt_id => $this->entity->receipt_id,
            self::table_id => $this->entity->table_id,
            self::record_key => $this->entity->record_key,
            self::record_key_hash => $this->entity->record_key_hash,
            self::mutation_type => $this->entity->mutation_type,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Column name
     * @param mixed $value New scalar value
     * @throws DatabaseException When the property cannot be written
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::created_at => $this->entity->created_at = (string)$value,
            self::receipt_id => $this->entity->receipt_id = $value === null ? null : (int)$value,
            self::table_id => $this->entity->table_id = (int)$value,
            self::record_key => $this->entity->record_key = (string)$value,
            self::record_key_hash => $this->entity->record_key_hash = $value === null ? null : (string)$value,
            self::mutation_type => $this->entity->mutation_type = (string)$value,
            default => parent::__set($property, $value),
        };
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ChangeLogChange as EntityChangeLogChange;
use Hilos\Database\Object\Collection\ChangeLogChanges as ObjectChangeLogChanges;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/**
 * @extends Object_<EntityChangeLogChange>
 *
 * @property-read ?int $id
 * @property string $created_at
 * @property int $log_id
 * @property int $field_id
 * @property string $kind
 * @property bool $old_present
 * @property bool $new_present
 * @property ?string $old_value
 * @property ?string $new_value
 */
class ChangeLogChange extends Object_
{
    public const string ENTITY_CLASS = EntityChangeLogChange::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogChanges::class;

    public const string id = EntityChangeLogChange::id;
    public const string created_at = EntityChangeLogChange::created_at;
    public const string log_id = EntityChangeLogChange::log_id;
    public const string field_id = EntityChangeLogChange::field_id;
    public const string kind = EntityChangeLogChange::kind;
    public const string old_present = EntityChangeLogChange::old_present;
    public const string new_present = EntityChangeLogChange::new_present;
    public const string old_value = EntityChangeLogChange::old_value;
    public const string new_value = EntityChangeLogChange::new_value;

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
            self::log_id => $this->entity->log_id,
            self::field_id => $this->entity->field_id,
            self::kind => $this->entity->kind,
            self::old_present => $this->entity->old_present,
            self::new_present => $this->entity->new_present,
            self::old_value => $this->entity->old_value,
            self::new_value => $this->entity->new_value,
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
            self::log_id => $this->entity->log_id = (int)$value,
            self::field_id => $this->entity->field_id = (int)$value,
            self::kind => $this->entity->kind = (string)$value,
            self::old_present => $this->entity->old_present = (bool)$value,
            self::new_present => $this->entity->new_present = (bool)$value,
            self::old_value => $this->entity->old_value = $value === null ? null : (string)$value,
            self::new_value => $this->entity->new_value = $value === null ? null : (string)$value,
            default => parent::__set($property, $value),
        };
    }
}

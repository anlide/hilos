<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ChangeLogValue as EntityChangeLogValue;
use Hilos\Database\Object\Collection\ChangeLogValues as ObjectChangeLogValues;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/**
 * @extends Object_<EntityChangeLogValue>
 *
 * @property-read ?int $id
 * @property string $created_at
 * @property int $change_id
 * @property ?string $old_value
 * @property ?string $new_value
 */
class ChangeLogValue extends Object_
{
    public const string ENTITY_CLASS = EntityChangeLogValue::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogValues::class;

    public const string id = EntityChangeLogValue::id;
    public const string created_at = EntityChangeLogValue::created_at;
    public const string change_id = EntityChangeLogValue::change_id;
    public const string old_value = EntityChangeLogValue::old_value;
    public const string new_value = EntityChangeLogValue::new_value;

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
            self::change_id => $this->entity->change_id,
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
            self::change_id => $this->entity->change_id = (int)$value,
            self::old_value => $this->entity->old_value = $value === null ? null : (string)$value,
            self::new_value => $this->entity->new_value = $value === null ? null : (string)$value,
            default => parent::__set($property, $value),
        };
    }
}

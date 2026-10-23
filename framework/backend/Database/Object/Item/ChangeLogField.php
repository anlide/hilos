<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ChangeLogField as EntityChangeLogField;
use Hilos\Database\Object\Collection\ChangeLogFields as ObjectChangeLogFields;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/**
 * @extends Object_<EntityChangeLogField>
 *
 * @property-read ?int $id
 * @property int $table_id
 * @property string $name
 */
class ChangeLogField extends Object_
{
    public const string ENTITY_CLASS = EntityChangeLogField::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogFields::class;

    public const string id = EntityChangeLogField::id;
    public const string table_id = EntityChangeLogField::table_id;
    public const string name = EntityChangeLogField::name;

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
            self::table_id => $this->entity->table_id,
            self::name => $this->entity->name,
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
            self::table_id => $this->entity->table_id = (int)$value,
            self::name => $this->entity->name = (string)$value,
            default => parent::__set($property, $value),
        };
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ChangeLogTable as EntityChangeLogTable;
use Hilos\Database\Object\Collection\ChangeLogTables as ObjectChangeLogTables;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/**
 * @extends Object_<EntityChangeLogTable>
 *
 * @property-read ?int $id
 * @property string $name
 */
class ChangeLogTable extends Object_
{
    public const string ENTITY_CLASS = EntityChangeLogTable::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogTables::class;

    public const string id = EntityChangeLogTable::id;
    public const string name = EntityChangeLogTable::name;

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
            self::name => $this->entity->name = (string)$value,
            default => parent::__set($property, $value),
        };
    }
}

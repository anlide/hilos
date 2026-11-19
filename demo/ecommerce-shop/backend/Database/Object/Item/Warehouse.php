<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\Warehouse as EntityWarehouse;
use Demo\EcommerceShop\Database\Object\Collection\Warehouses as ObjectWarehouses;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * Warehouse - Object wrapper for warehouse entity.
 *
 * @extends Object_<EntityWarehouse>
 *
 * @property-read ?int $id
 * @property string $name
 * @property string $address
 */
final class Warehouse extends Object_
{
    public const string ENTITY_CLASS = EntityWarehouse::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectWarehouses::class;

    public const string id = 'id';
    public const string name = 'name';
    public const string address = 'address';

    /**
     * Returns the value of a warehouse object property by name.
     *
     * @param string $property Property name (id, name, address)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity or collection access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            self::address => $this->entity->address,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a warehouse object property.
     *
     * @param string $property Property name to set (name, address)
     * @param mixed $value New value
     * @throws DatabaseException If entity or collection access fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::name => $this->entity->name = (string)$value,
            self::address => $this->entity->address = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the warehouse object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            self::address => $this->entity->address,
        ];
    }
}

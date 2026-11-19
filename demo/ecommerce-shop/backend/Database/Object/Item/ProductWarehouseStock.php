<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Item;

use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Collection\ProductWarehouseStocks as ObjectProductWarehouseStocks;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * ProductWarehouseStock - Object wrapper for product_warehouse_stock entity.
 *
 * @extends Object_<EntityProductWarehouseStock>
 *
 * @property-read ?int $id
 * @property int $productId
 * @property int $warehouseId
 * @property int $units
 */
final class ProductWarehouseStock extends Object_
{
    public const string ENTITY_CLASS = EntityProductWarehouseStock::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectProductWarehouseStocks::class;

    public const string id = 'id';
    public const string productId = 'productId';
    public const string warehouseId = 'warehouseId';
    public const string units = 'units';

    /**
     * Returns the value of a stock object property by name.
     *
     * @param string $property Property name (id, productId, warehouseId, units)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity or collection access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::productId => $this->entity->product_id,
            self::warehouseId => $this->entity->warehouse_id,
            self::units => $this->entity->units,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a stock object property.
     *
     * @param string $property Property name to set (productId, warehouseId, units)
     * @param mixed $value New value
     * @throws DatabaseException If entity or collection access fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::productId => $this->entity->product_id = (int)$value,
            self::warehouseId => $this->entity->warehouse_id = (int)$value,
            self::units => $this->entity->units = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the stock object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::productId => $this->entity->product_id,
            self::warehouseId => $this->entity->warehouse_id,
            self::units => $this->entity->units,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * ProductWarehouseStock - Db item with high-level abstraction and lazy loading.
 *
 * A row of stock has no writes of its own: the stock library sets the units and places a unique
 * piece through the collection.
 *
 * @extends DbItem<ObjectProductWarehouseStock>
 * @method __construct(ObjectProductWarehouseStock $objectProductWarehouseStock)
 *
 * @property-read ?int $id
 * @property-read int $productId Product the units are of
 * @property-read int $warehouseId Warehouse that keeps them
 * @property-read int $units How many units, one at least
 */
final class ProductWarehouseStock extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name (id, productId, warehouseId, units)
     * @return int|null Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): ?int
    {
        return match ($name) {
            ObjectProductWarehouseStock::id => $this->_object->id,
            ObjectProductWarehouseStock::productId => $this->_object->productId,
            ObjectProductWarehouseStock::warehouseId => $this->_object->warehouseId,
            ObjectProductWarehouseStock::units => $this->_object->units,
            default => parent::__get($name),
        };
    }
}

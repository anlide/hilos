<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Item;

use Demo\EcommerceShop\Database\Actions\Item\WarehouseActions;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\HilosException;

/**
 * Warehouse - Db item with high-level abstraction and lazy loading.
 *
 * @extends DbItem<ObjectWarehouse>
 * @method __construct(ObjectWarehouse $objectWarehouse)
 *
 * @property-read ?int $id
 * @property-read string $name
 * @property-read string $address
 * @property-read WarehouseActions $actions Item-level write operations
 */
final class Warehouse extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name (id, name, address, actions)
     * @return int|string|WarehouseActions|null Property value or actions
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): int|string|WarehouseActions|null
    {
        return match ($name) {
            ObjectWarehouse::id => $this->_object->id,
            ObjectWarehouse::name => $this->_object->name,
            ObjectWarehouse::address => $this->_object->address,
            default => parent::__get($name),
        };
    }
}

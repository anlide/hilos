<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\WarehousesActions;
use Demo\EcommerceShop\Database\Object\Collection\Warehouses as ObjectWarehouses;
use Demo\EcommerceShop\Database\View\Item\Warehouse;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Warehouses - Db collection of Warehouse items.
 *
 * @extends DbCollection<Warehouse, ObjectWarehouses>
 * @method ObjectWarehouses|null getObjectCollection()
 * @method Warehouse|null current()
 * @method Warehouse|null first()
 * @method Warehouse|null last()
 * @method Warehouse offsetGet(mixed $offset)
 * @property-read WarehousesActions $actions Collection actions (create); items use WarehouseActions
 */
final class Warehouses extends DbCollection
{
    public const string DB_ITEM_CLASS = Warehouse::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectWarehouses::class;
}

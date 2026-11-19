<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\ProductWarehouseStocksActions;
use Demo\EcommerceShop\Database\Object\Collection\ProductWarehouseStocks as ObjectProductWarehouseStocks;
use Demo\EcommerceShop\Database\View\Item\ProductWarehouseStock;
use Hilos\Database\View\Collection\DbCollection;

/**
 * ProductWarehouseStocks - Db collection of ProductWarehouseStock items: the units of each
 * product in each warehouse.
 *
 * @extends DbCollection<ProductWarehouseStock, ObjectProductWarehouseStocks>
 * @method ObjectProductWarehouseStocks|null getObjectCollection()
 * @method ProductWarehouseStock|null current()
 * @method ProductWarehouseStock|null first()
 * @method ProductWarehouseStock|null last()
 * @method ProductWarehouseStock offsetGet(mixed $offset)
 * @property-read ProductWarehouseStocksActions $actions Collection actions (setUnits, place), for the stock library only
 */
final class ProductWarehouseStocks extends DbCollection
{
    public const string DB_ITEM_CLASS = ProductWarehouseStock::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectProductWarehouseStocks::class;
}

<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\ProductWarehouseStocksActions;
use Demo\EcommerceShop\Database\DTO\ProductStockTotals;
use Demo\EcommerceShop\Database\DTO\ProductStockTotalsCollection;
use Demo\EcommerceShop\Database\DTO\WarehouseStockTotals;
use Demo\EcommerceShop\Database\Object\Collection\ProductWarehouseStocks as ObjectProductWarehouseStocks;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\View\Item\ProductWarehouseStock;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
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

    /**
     * Resolves the product of a stock fact without adding a cold stock row to the worker's ORM.
     *
     * @param int $stockId Primary key of the changed warehouse stock row
     * @return ?int Product key, or null when no row holds the stock key
     * @throws LogicException When the Object collection is misconfigured
     * @throws InvalidArgumentException When the Entity lookup rejects the query
     * @throws DatabaseException When the lookup or the held object read fails
     */
    public function productIdAtStockKey(int $stockId): ?int
    {
        return $this->getObjectCollection()?->productIdAtStockKey($stockId);
    }

    /**
     * Counts the products one warehouse keeps and sums their units.
     *
     * The stock library holds the whole set and answers from memory; any other process finds the
     * warehouse's rows by SQL on the warehouse index, and a row it already holds is read as the
     * last sync left it.
     *
     * @param int $warehouseId Id of the warehouse
     * @return WarehouseStockTotals Its stock rows counted and their units summed; zeros for a warehouse keeping nothing
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match the collection
     * @throws DatabaseException When the rows cannot be read
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     */
    public function totalsInWarehouse(int $warehouseId): WarehouseStockTotals
    {
        $productCount = 0;
        $unitCount = 0;
        foreach ($this->whereColumnIs(ObjectProductWarehouseStock::warehouseId, $warehouseId) as $stock) {
            $productCount++;
            $unitCount += $stock->units;
        }

        return new WarehouseStockTotals($productCount, $unitCount);
    }

    /**
     * Sums the units of each of several products and names the warehouse of a product kept in one.
     *
     * The stock library holds the whole set and answers from memory; any other process finds the
     * products' rows by one SQL query on the product index, and a row it already holds is read as
     * the last sync left it. No ids ask nothing and read nothing.
     *
     * @param int ...$productIds Ids of the products
     * @return ProductStockTotalsCollection Totals by product id, one for every id asked about; zero
     *     units, zero warehouses and no warehouse for a product no warehouse keeps; empty for no ids
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match the collection
     * @throws DatabaseException When the rows cannot be read
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     */
    public function totalsOfProducts(int ...$productIds): ProductStockTotalsCollection
    {
        $unitCounts = array_fill_keys($productIds, 0);
        $warehouseIds = array_fill_keys($productIds, []);
        foreach ($this->whereColumnIn(ObjectProductWarehouseStock::productId, ...$productIds) as $stock) {
            $unitCounts[$stock->productId] += $stock->units;
            $warehouseIds[$stock->productId][] = $stock->warehouseId;
        }

        $totals = [];
        foreach ($unitCounts as $productId => $unitCount) {
            $totals[$productId] = new ProductStockTotals(
                $unitCount,
                count($warehouseIds[$productId]) === 1 ? $warehouseIds[$productId][0] : null,
                count($warehouseIds[$productId]),
            );
        }

        return new ProductStockTotalsCollection($totals);
    }
}

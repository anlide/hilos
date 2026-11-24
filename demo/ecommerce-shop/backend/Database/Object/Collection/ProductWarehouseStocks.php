<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\ProductWarehouseStocks as EntityProductWarehouseStocks;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;

/**
 * ProductWarehouseStocks - Object collection for the units of each product in each warehouse.
 *
 * @extends Objects<ObjectProductWarehouseStock>
 * @method ObjectProductWarehouseStock|null current()
 * @method ObjectProductWarehouseStock|null first()
 * @method ObjectProductWarehouseStock|null last()
 * @method ObjectProductWarehouseStock|null get(int|string $key)
 * @method ObjectProductWarehouseStock|null offsetGet(mixed $offset)
 */
final class ProductWarehouseStocks extends Objects
{
    public const string OBJECT_CLASS = ObjectProductWarehouseStock::class;
    public const string ENTITY_COLLECTION_CLASS = EntityProductWarehouseStocks::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::productWarehouseStocks;

    /**
     * Sums the persisted units across warehouses for a product without changing the Object store.
     *
     * @param int $productId Product primary key
     * @return int Stored units, zero when no warehouse keeps the product
     * @throws DatabaseException When the stock rows cannot be read
     * @throws InvalidArgumentException When the Entity query is invalid
     */
    public function storedUnitsOfProduct(int $productId): int
    {
        $stocks = static::entityClass()::get( // fresh-sql: a cart increase can arrive before the worker receives the stock update.
            [EntityProductWarehouseStock::product_id => $productId],
        );
        $units = 0;
        foreach ($stocks as $stock) {
            $units += $stock->units;
        }

        return $units;
    }

    /**
     * Reads a held stock key directly or uses the Entity's keyed query without hydrating the Object collection.
     *
     * @param int $stockId Primary key of the changed warehouse stock row
     * @return ?int Product key, or null when the row is absent
     * @throws LogicException When the Object collection is misconfigured
     * @throws InvalidArgumentException When the Entity lookup rejects the query
     * @throws DatabaseException When the lookup or the held object read fails
     */
    public function productIdAtStockKey(int $stockId): ?int
    {
        if (isset($this[$stockId])) {
            return $this[$stockId]?->productId;
        }
        if ($this->isAllLoaded()) {
            return null;
        }

        return EntityProductWarehouseStock::getById($stockId)?->product_id;
    }
}

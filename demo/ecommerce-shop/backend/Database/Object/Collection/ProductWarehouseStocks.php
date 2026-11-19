<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\ProductWarehouseStocks as EntityProductWarehouseStocks;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
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
}

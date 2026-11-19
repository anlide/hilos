<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\Warehouses as EntityWarehouses;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Hilos\Database\Object\Objects;

/**
 * Warehouses - Object collection for warehouses.
 *
 * @extends Objects<ObjectWarehouse>
 * @method ObjectWarehouse|null current()
 * @method ObjectWarehouse|null first()
 * @method ObjectWarehouse|null last()
 * @method ObjectWarehouse|null get(int|string $key)
 * @method ObjectWarehouse|null offsetGet(mixed $offset)
 */
final class Warehouses extends Objects
{
    public const string OBJECT_CLASS = ObjectWarehouse::class;
    public const string ENTITY_COLLECTION_CLASS = EntityWarehouses::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::warehouses;
}

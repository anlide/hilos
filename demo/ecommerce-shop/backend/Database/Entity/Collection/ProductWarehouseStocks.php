<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * ProductWarehouseStocks - entity collection for ProductWarehouseStock entities.
 *
 * @extends EntityCollection<EntityProductWarehouseStock>
 * @implements IteratorAggregate<int|string, EntityProductWarehouseStock>
 * @implements ArrayAccess<int|string, EntityProductWarehouseStock>
 */
final class ProductWarehouseStocks extends EntityCollection
{
    public const string ENTITY_CLASS = EntityProductWarehouseStock::class;
}

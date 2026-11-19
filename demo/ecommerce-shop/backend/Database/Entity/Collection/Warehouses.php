<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\Warehouse as EntityWarehouse;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * Warehouses - entity collection for Warehouse entities.
 *
 * @extends EntityCollection<EntityWarehouse>
 * @implements IteratorAggregate<int|string, EntityWarehouse>
 * @implements ArrayAccess<int|string, EntityWarehouse>
 */
final class Warehouses extends EntityCollection
{
    public const string ENTITY_CLASS = EntityWarehouse::class;
}

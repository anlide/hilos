<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * Products - entity collection for Product entities.
 *
 * @extends EntityCollection<EntityProduct>
 * @implements IteratorAggregate<int|string, EntityProduct>
 * @implements ArrayAccess<int|string, EntityProduct>
 */
final class Products extends EntityCollection
{
    public const string ENTITY_CLASS = EntityProduct::class;
}

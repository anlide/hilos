<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart as EntityUserProductCart;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * UserProductCarts - entity collection for UserProductCart entities.
 *
 * @extends EntityCollection<EntityUserProductCart>
 * @implements IteratorAggregate<int|string, EntityUserProductCart>
 * @implements ArrayAccess<int|string, EntityUserProductCart>
 */
final class UserProductCarts extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUserProductCart::class;
}

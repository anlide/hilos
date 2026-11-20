<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart as EntitySessionProductCart;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * SessionProductCarts - entity collection for SessionProductCart entities.
 *
 * @extends EntityCollection<EntitySessionProductCart>
 * @implements IteratorAggregate<int|string, EntitySessionProductCart>
 * @implements ArrayAccess<int|string, EntitySessionProductCart>
 */
final class SessionProductCarts extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySessionProductCart::class;
}

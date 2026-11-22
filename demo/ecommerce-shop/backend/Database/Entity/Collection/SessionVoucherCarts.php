<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\SessionVoucherCart as EntitySessionVoucherCart;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntitySessionVoucherCart>
 * @implements IteratorAggregate<int|string, EntitySessionVoucherCart>
 * @implements ArrayAccess<int|string, EntitySessionVoucherCart>
 */
final class SessionVoucherCarts extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySessionVoucherCart::class;
}

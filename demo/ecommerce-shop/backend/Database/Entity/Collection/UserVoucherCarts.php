<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use ArrayAccess;
use Demo\EcommerceShop\Database\Entity\Item\UserVoucherCart as EntityUserVoucherCart;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityUserVoucherCart>
 * @implements IteratorAggregate<int|string, EntityUserVoucherCart>
 * @implements ArrayAccess<int|string, EntityUserVoucherCart>
 */
final class UserVoucherCarts extends EntityCollection
{
    public const string ENTITY_CLASS = EntityUserVoucherCart::class;
}

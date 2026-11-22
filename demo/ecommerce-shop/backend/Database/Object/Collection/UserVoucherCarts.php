<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\UserVoucherCarts as EntityUserVoucherCarts;
use Demo\EcommerceShop\Database\Object\Item\UserVoucherCart as ObjectUserVoucherCart;
use Hilos\Database\Object\Objects;

/**
 * Voucher bindings keyed by person ID.
 *
 * @extends Objects<ObjectUserVoucherCart>
 * @method ObjectUserVoucherCart|null first()
 * @method ObjectUserVoucherCart|null last()
 * @method ObjectUserVoucherCart|null get(int|string $key)
 * @method ObjectUserVoucherCart|null offsetGet(mixed $offset)
 */
final class UserVoucherCarts extends Objects
{
    public const string OBJECT_CLASS = ObjectUserVoucherCart::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserVoucherCarts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::userVoucherCarts;
}

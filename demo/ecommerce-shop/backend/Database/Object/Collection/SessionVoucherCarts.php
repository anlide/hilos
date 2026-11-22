<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\SessionVoucherCarts as EntitySessionVoucherCarts;
use Demo\EcommerceShop\Database\Object\Item\SessionVoucherCart as ObjectSessionVoucherCart;
use Hilos\Database\Object\Objects;

/**
 * Voucher bindings keyed by browser session ID.
 *
 * @extends Objects<ObjectSessionVoucherCart>
 * @method ObjectSessionVoucherCart|null first()
 * @method ObjectSessionVoucherCart|null last()
 * @method ObjectSessionVoucherCart|null get(int|string $key)
 * @method ObjectSessionVoucherCart|null offsetGet(mixed $offset)
 */
final class SessionVoucherCarts extends Objects
{
    public const string OBJECT_CLASS = ObjectSessionVoucherCart::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySessionVoucherCarts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::sessionVoucherCarts;
}

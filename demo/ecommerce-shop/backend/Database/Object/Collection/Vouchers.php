<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\Vouchers as EntityVouchers;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Hilos\Database\Object\Objects;

/**
 * Vouchers - Object collection for vouchers.
 *
 * @extends Objects<ObjectVoucher>
 * @method ObjectVoucher|null current()
 * @method ObjectVoucher|null first()
 * @method ObjectVoucher|null last()
 * @method ObjectVoucher|null get(int|string $key)
 * @method ObjectVoucher|null offsetGet(mixed $offset)
 */
final class Vouchers extends Objects
{
    public const string OBJECT_CLASS = ObjectVoucher::class;
    public const string ENTITY_COLLECTION_CLASS = EntityVouchers::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::vouchers;
}

<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\UserVoucherCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\UserVoucherCarts as ObjectUserVoucherCarts;
use Demo\EcommerceShop\Database\View\Item\UserVoucherCart;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Voucher bindings keyed by person ID.
 *
 * @extends DbCollection<UserVoucherCart, ObjectUserVoucherCarts>
 * @method ObjectUserVoucherCarts|null getObjectCollection()
 * @method UserVoucherCart|null first()
 * @method UserVoucherCart|null last()
 * @method UserVoucherCart offsetGet(mixed $offset)
 * @property-read UserVoucherCartsActions $actions Creation for the person's agent
 */
final class UserVoucherCarts extends DbCollection
{
    public const string DB_ITEM_CLASS = UserVoucherCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserVoucherCarts::class;
}

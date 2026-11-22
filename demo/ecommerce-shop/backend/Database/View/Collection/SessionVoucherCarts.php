<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\SessionVoucherCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\SessionVoucherCarts as ObjectSessionVoucherCarts;
use Demo\EcommerceShop\Database\View\Item\SessionVoucherCart;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Voucher bindings keyed by browser session ID.
 *
 * @extends DbCollection<SessionVoucherCart, ObjectSessionVoucherCarts>
 * @method ObjectSessionVoucherCarts|null getObjectCollection()
 * @method SessionVoucherCart|null first()
 * @method SessionVoucherCart|null last()
 * @method SessionVoucherCart offsetGet(mixed $offset)
 * @property-read SessionVoucherCartsActions $actions Creation for the guest carts library
 */
final class SessionVoucherCarts extends DbCollection
{
    public const string DB_ITEM_CLASS = SessionVoucherCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessionVoucherCarts::class;
}

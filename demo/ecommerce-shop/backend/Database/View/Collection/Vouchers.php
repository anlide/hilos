<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\VouchersActions;
use Demo\EcommerceShop\Database\Object\Collection\Vouchers as ObjectVouchers;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Vouchers - Db collection of Voucher items.
 *
 * @extends DbCollection<Voucher, ObjectVouchers>
 * @method ObjectVouchers|null getObjectCollection()
 * @method Voucher|null current()
 * @method Voucher|null first()
 * @method Voucher|null last()
 * @method Voucher offsetGet(mixed $offset)
 * @property-read VouchersActions $actions Collection actions (create); items use VoucherActions
 */
final class Vouchers extends DbCollection
{
    public const string DB_ITEM_CLASS = Voucher::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectVouchers::class;
}

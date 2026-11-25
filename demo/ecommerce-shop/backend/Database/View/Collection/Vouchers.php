<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\VouchersActions;
use Demo\EcommerceShop\Database\Object\Collection\Vouchers as ObjectVouchers;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Hilos\Database\DatabaseException;
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

    /**
     * @param string $code Normalized voucher code
     * @return array{id: int, active: bool}|null Current voucher identity and activity locked through the caller's transaction
     * @throws DatabaseException When no row-connection transaction is open or the locked read fails
     */
    public function lockStoredByCode(string $code): ?array
    {
        return $this->objectCollection->lockStoredByCode($code);
    }
}

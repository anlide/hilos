<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\Vouchers as EntityVouchers;
use Demo\EcommerceShop\Database\Entity\Item\Voucher as EntityVoucher;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Hilos\Database\DatabaseException;
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

    /**
     * Reads storage because deactivation can commit before the worker receives its catalog sync.
     * The shared lock holds the decision through the caller's binding write.
     *
     * @param string $code Normalized voucher code
     * @return array{id: int, active: bool}|null Stored voucher identity and activity, or null when absent
     * @throws DatabaseException When no row-connection transaction is open or the locked read fails
     */
    public function lockStoredByCode(string $code): ?array
    {
        $index = EntityVoucher::getDatabase()->connectionIndex;
        if (Database::runtimeTransactionLevel($index) === null) {
            throw new DatabaseException('The voucher lock requires the row connection transaction.');
        }
        $row = Database::sql(
            'SELECT `' . EntityVoucher::id . '`, `' . EntityVoucher::active . '` FROM ' . EntityVoucher::getSqlTableName()
            . ' WHERE `' . EntityVoucher::code . '` = ? LOCK IN SHARE MODE NOWAIT',
            [$code],
            index: $index,
        )->firstRow();

        if ($row === null) {
            return null;
        }

        return [ObjectVoucher::id => (int)$row[EntityVoucher::id], ObjectVoucher::active => (bool)$row[EntityVoucher::active]];
    }
}

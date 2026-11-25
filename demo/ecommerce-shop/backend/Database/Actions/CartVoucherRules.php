<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions;

use Closure;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Throwable;

/** Voucher selection and discount rules shared by guest and account carts. */
final class CartVoucherRules
{
    /**
     * Keeps the current voucher locked through the binding write, joining a caller's open transaction when present.
     *
     * @param string $code Code as entered
     * @param Closure(int): void $bind Binding write performed by the cart owner
     * @throws EmptyValueException When the trimmed code is empty
     * @throws ValueTooLongException When the code exceeds the catalog limit
     * @throws ValidationException When the code is malformed, unknown or inactive
     * @throws DatabaseException When the catalog read fails
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     * @throws NestedTransactionRefusedException When a transaction cannot be opened
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws Throwable When the binding write raises a failure
     */
    public static function applyVoucher(string $code, Closure $bind): void
    {
        $normalized = VoucherFields::code($code);
        $apply = static function () use ($normalized, $bind): void {
            $voucher = Hilos::$db->vouchers->lockStoredByCode($normalized);
            if ($voucher === null) {
                throw new ValidationException('Voucher code was not found');
            }
            if (!$voucher[ObjectVoucher::active]) {
                throw new ValidationException('This voucher is inactive');
            }

            $bind($voucher[ObjectVoucher::id]);
        };

        $level = Database::runtimeTransactionLevel(Database::getDatabase(DatabaseNames::PRIMARY)->connectionIndex);
        if ($level !== null && $level->testScope === null) {
            $apply();

            return;
        }

        Database::transaction(DatabaseNames::PRIMARY, $apply);
    }

    /**
     * The one activity rule shared by the cart totals: only an active voucher takes its percentage off.
     *
     * @param ?Voucher $voucher Current voucher read from the catalog by its saved ID, or null for an empty slot
     * @return int Current discount as a whole percent, or zero when no active voucher is selected
     */
    public static function effectiveDiscountPercent(?Voucher $voucher): int
    {
        return $voucher?->active === true ? $voucher->discountPercent : 0;
    }
}

<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions;

use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/** Voucher selection and discount rules shared by guest and account carts. */
final class CartVoucherRules
{
    /**
     * Resolves the entered code to an active catalog voucher.
     *
     * @param string $code Code as entered
     * @return Voucher Active voucher matched by the normalized code
     * @throws EmptyValueException When the trimmed code is empty
     * @throws ValueTooLongException When the code exceeds the catalog limit
     * @throws ValidationException When the code is malformed, unknown or inactive
     * @throws LogicException When the catalog collection is misconfigured
     * @throws InvalidArgumentException When the code column or voucher object does not match the catalog
     * @throws DatabaseException When the catalog read fails
     * @throws ObjectGetIdStringNotImplementedException When a matched voucher cannot be keyed
     * @throws CollectionNotManualException When the selection cannot accept the matched voucher
     */
    public static function voucherForCode(string $code): Voucher
    {
        $normalized = VoucherFields::code($code);
        $voucher = Hilos::$db->vouchers->whereColumnIs(ObjectVoucher::code, $normalized)->first();
        if ($voucher === null) {
            throw new ValidationException('Voucher code was not found');
        }
        if (!$voucher->active) {
            throw new ValidationException('This voucher is inactive');
        }

        return $voucher;
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

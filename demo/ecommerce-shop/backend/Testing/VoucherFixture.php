<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Testing;

use Demo\EcommerceShop\Database\Actions\VoucherFields;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;

/** The code space reserved for voucher fixtures. */
final class VoucherFixture
{
    private const string CODE_PATTERN = '/\AZZE2EV[0-9A-F]{24}\z/';

    /**
     * @param string $value Code as entered
     * @return string Canonical code in the reserved fixture space
     * @throws EmptyValueException When the code is empty
     * @throws ValueTooLongException When the code exceeds its column width
     * @throws ValidationException When the code falls outside the fixture space
     */
    public static function code(string $value): string
    {
        $code = VoucherFields::code($value);
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new ValidationException('A voucher fixture code must be ZZE2EV followed by 24 hexadecimal digits');
        }

        return $code;
    }
}

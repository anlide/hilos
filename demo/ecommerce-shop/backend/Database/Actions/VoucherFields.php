<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;

final class VoucherFields
{
    public const int CODE_MAX_LENGTH = 32;
    public const int DISCOUNT_PERCENT_MIN = 1;
    public const int DISCOUNT_PERCENT_MAX = 100;

    /**
     * @param string $value Code as entered
     * @return string Trimmed uppercase code
     * @throws EmptyValueException When the trimmed code is empty
     * @throws ValueTooLongException When the code is longer than its column
     * @throws ValidationException When the code contains anything besides ASCII letters and digits
     */
    public static function code(string $value): string
    {
        $code = trim($value);
        if ($code === '') {
            throw new EmptyValueException('Voucher code cannot be empty');
        }
        if (mb_strlen($code) > self::CODE_MAX_LENGTH) {
            throw new ValueTooLongException('Voucher code exceeds maximum length of ' . self::CODE_MAX_LENGTH . ' characters');
        }
        if (preg_match('/^[A-Za-z0-9]+$/', $code) !== 1) {
            throw new ValidationException('Voucher code may only contain Latin letters and digits');
        }

        return strtoupper($code);
    }

    /**
     * @param int $value Discount as a whole percentage
     * @return int Discount within the allowed range
     * @throws ValidationException When the percentage is outside the allowed range
     */
    public static function discountPercent(int $value): int
    {
        if ($value < self::DISCOUNT_PERCENT_MIN || $value > self::DISCOUNT_PERCENT_MAX) {
            throw new ValidationException(
                'Discount must be a whole percent from ' . self::DISCOUNT_PERCENT_MIN . ' to ' . self::DISCOUNT_PERCENT_MAX,
            );
        }

        return $value;
    }
}

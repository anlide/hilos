<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValueTooLongException;

/**
 * WarehouseFields - the one place the fields of a warehouse are checked.
 *
 * Creating a warehouse and editing one take the same name and the same address, so both read
 * them through here. The limits are those of the columns.
 */
final class WarehouseFields
{
    public const int NAME_MAX_LENGTH = 100;
    public const int ADDRESS_MAX_LENGTH = 255;

    /**
     * Checks a warehouse name and returns it trimmed.
     *
     * @param string $value Name as entered
     * @return string The trimmed name
     * @throws EmptyValueException When nothing but spaces is left
     * @throws ValueTooLongException When the name is longer than the column
     */
    public static function name(string $value): string
    {
        $name = trim($value);
        if ($name === '') {
            throw new EmptyValueException('Warehouse name cannot be empty');
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new ValueTooLongException('Warehouse name exceeds maximum length of ' . self::NAME_MAX_LENGTH . ' characters');
        }

        return $name;
    }

    /**
     * Checks a warehouse address and returns it trimmed.
     *
     * @param string $value Address as entered
     * @return string The trimmed address
     * @throws EmptyValueException When nothing but spaces is left
     * @throws ValueTooLongException When the address is longer than the column
     */
    public static function address(string $value): string
    {
        $address = trim($value);
        if ($address === '') {
            throw new EmptyValueException('Warehouse address cannot be empty');
        }
        if (mb_strlen($address) > self::ADDRESS_MAX_LENGTH) {
            throw new ValueTooLongException(
                'Warehouse address exceeds maximum length of ' . self::ADDRESS_MAX_LENGTH . ' characters',
            );
        }

        return $address;
    }
}

<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Money;

use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;

/**
 * Money - the one place the shop counts and prints its money.
 *
 * An amount is a whole number of kopecks, never below zero. A line costs its price times its
 * quantity, the lines add up exactly, and a discount is the only operation that rounds: a share
 * of the items, half a kopeck and more going up. Nothing rounded is kept anywhere, so the
 * discount and the total are worked out again every time they are shown and at the moment of
 * purchase. An amount is printed as the hryvnia sign, the hryvnias with a comma between the
 * thousands and the kopecks after a point only when there are any: ₴2,525, ₴250.50.
 *
 * A number that breaks a precondition comes from the database or from this class, not from a
 * person, so it is a mistake of the program. Only text a person wrote is refused in words.
 */
final class Money
{
    private const int KOPECKS_IN_HRYVNIA = 100;
    private const int WHOLE_PERCENT = 100;

    /** Half of a whole percent: added before the division, it rounds half a kopeck up. */
    private const int HALF_KOPECK = 50;

    private const string SIGN = '₴';
    private const string THOUSANDS_SEPARATOR = ',';
    private const string DECIMAL_POINT = '.';
    private const int KOPECK_DIGITS = 2;
    private const string KOPECK_PAD = '0';

    private const int DECIMAL_BASE = 10;
    private const string EXACT_AMOUNT = '/^(?:0|[1-9][0-9]*)\z/';

    /** Longest whole part a person may write; even the longest one stays far from an overflow. */
    private const int HRYVNIA_MAX_DIGITS = 15;

    /** A space a person or a pasted text may put between the digits: plain, no-break, narrow no-break. */
    private const array SPACES = [' ', "\u{00A0}", "\u{202F}"];

    /** The same three spaces as bytes of UTF-8, so the pattern never fails on a malformed text. */
    private const string SPACE = '(?: |\xC2\xA0|\xE2\x80\xAF)';

    /** Digits with spaces between them, then a point or a comma and the digits after it. */
    private const string AMOUNT = '/^' . self::SPACE . '*(\d+(?:' . self::SPACE . '+\d+)*)(?:[.,](\d+))?' . self::SPACE . '*$/D';

    private const string THOUSANDS = '/\B(?=(?:\d{3})+$)/';

    private const string NOT_AN_AMOUNT = 'Write the amount in hryvnias, like 410 or 2 100.50';
    private const string TOO_MANY_KOPECK_DIGITS = 'Write at most two digits after the point';
    private const string TOO_LARGE = 'This amount is too large';

    /**
     * Prints an amount the way the shop shows it.
     *
     * @param int $kopecks Amount in kopecks
     * @return string The sign, the hryvnias in groups of three and the kopecks when there are any: ₴1,234,567.89
     * @throws LogicException When the amount is below zero or the grouping pattern cannot be applied
     */
    public static function format(int $kopecks): string
    {
        self::requireNotNegative($kopecks, 'An amount');
        $hryvnias = preg_replace(self::THOUSANDS, self::THOUSANDS_SEPARATOR, (string) intdiv($kopecks, self::KOPECKS_IN_HRYVNIA))
            ?? throw new LogicException('The hryvnias cannot be grouped: ' . preg_last_error_msg());
        $rest = $kopecks % self::KOPECKS_IN_HRYVNIA;
        if ($rest === 0) {
            return self::SIGN . $hryvnias;
        }

        return self::SIGN . $hryvnias . self::DECIMAL_POINT . str_pad((string) $rest, self::KOPECK_DIGITS, self::KOPECK_PAD, STR_PAD_LEFT);
    }

    /**
     * Reads an amount a person wrote in hryvnias.
     *
     * Spaces at both ends and between the digits are dropped; what is left is digits, then
     * optionally a point or a comma and one or two digits of kopecks. A comma is the decimal
     * mark, never a thousands separator, so 99,90 reads right and 2,100 is refused rather than
     * read as 2.10. Zero is read; whether it is allowed is for the caller to say.
     *
     * @param string $text Amount as written
     * @return int The amount in kopecks
     * @throws ValidationException When the text is not an amount, has over two digits after the point or over fifteen before it
     * @throws LogicException When the amount pattern cannot be applied to the text
     */
    public static function parse(string $text): int
    {
        $parts = [];
        $matched = preg_match(self::AMOUNT, $text, $parts);
        if ($matched === false) {
            throw new LogicException('The amount pattern cannot be applied: ' . preg_last_error_msg());
        }
        if ($matched === 0) {
            throw new ValidationException(self::NOT_AN_AMOUNT);
        }
        $fraction = $parts[2] ?? null;
        if ($fraction !== null && strlen($fraction) > self::KOPECK_DIGITS) {
            throw new ValidationException(self::TOO_MANY_KOPECK_DIGITS);
        }
        $whole = str_replace(self::SPACES, '', $parts[1]);
        if (strlen($whole) > self::HRYVNIA_MAX_DIGITS) {
            throw new ValidationException(self::TOO_LARGE);
        }
        $kopecks = $fraction === null ? 0 : (int) str_pad($fraction, self::KOPECK_DIGITS, self::KOPECK_PAD);

        return (int) $whole * self::KOPECKS_IN_HRYVNIA + $kopecks;
    }

    /**
     * Works out what a line costs.
     *
     * @param int $price Price of one unit, in kopecks
     * @param int $quantity Units on the line
     * @return int The price times the quantity, in kopecks
     * @throws LogicException When the price or the quantity is below zero
     */
    public static function lineTotal(int $price, int $quantity): int
    {
        self::requireNotNegative($price, 'A price');
        self::requireNotNegative($quantity, 'A quantity');

        return $price * $quantity;
    }

    /**
     * Adds amounts up; which of them belong in the sum is for the caller to say.
     *
     * @param int ...$amounts Amounts in kopecks
     * @return int Their sum in kopecks, zero for none
     * @throws LogicException When an amount is below zero
     */
    public static function sum(int ...$amounts): int
    {
        foreach ($amounts as $amount) {
            self::requireNotNegative($amount, 'An amount');
        }

        return array_sum($amounts);
    }

    /**
     * @param int $price Price of one unit, in kopecks
     * @param int $quantity Units on the line
     * @return string The exact price times quantity as decimal kopecks
     * @throws LogicException When the price or the quantity is below zero
     */
    public static function lineTotalExact(int $price, int $quantity): string
    {
        self::requireNotNegative($price, 'A price');
        self::requireNotNegative($quantity, 'A quantity');
        if ($quantity === 0 || $price <= intdiv(PHP_INT_MAX, $quantity)) {
            return (string) ($price * $quantity);
        }

        $total = '0';
        $addend = (string) $price;
        // Each successive bit of the quantity contributes twice as much as the previous one.
        while ($quantity !== 0) {
            if (($quantity & 1) === 1) {
                $total = self::addDecimal($total, $addend);
            }
            $quantity >>= 1;
            $addend = self::addDecimal($addend, $addend);
        }

        return $total;
    }

    /**
     * @param string ...$amounts Nonnegative decimal kopecks without leading zeroes
     * @return string The exact sum as decimal kopecks, "0" for none
     * @throws LogicException When an amount is not a canonical decimal string
     */
    public static function sumExact(string ...$amounts): string
    {
        $total = '0';
        foreach ($amounts as $amount) {
            if (preg_match(self::EXACT_AMOUNT, $amount) !== 1) {
                throw new LogicException('An exact amount must be written as nonnegative decimal kopecks');
            }
            $total = self::addDecimal($total, $amount);
        }

        return $total;
    }

    /**
     * Works out the discount on the items, the one operation that rounds.
     *
     * @param int $items Amount of the items, in kopecks
     * @param int $percent Discount, in whole percent from 0 to 100
     * @return int The share of the items, half a kopeck and more rounded up, in kopecks
     * @throws LogicException When the items are below zero or the percent is outside 0 to 100
     */
    public static function discountOf(int $items, int $percent): int
    {
        self::requireNotNegative($items, 'The items');
        if ($percent < 0 || $percent > self::WHOLE_PERCENT) {
            throw new LogicException("A discount of {$percent} percent is outside 0 to 100");
        }

        return intdiv($items * $percent + self::HALF_KOPECK, self::WHOLE_PERCENT);
    }

    /**
     * Works out what is left to pay once the discount is taken off the items.
     *
     * @param int $items Amount of the items, in kopecks
     * @param int $discount Discount, in kopecks
     * @return int The items less the discount, in kopecks
     * @throws LogicException When the items are below zero or the discount is outside zero to the items
     */
    public static function totalAfterDiscount(int $items, int $discount): int
    {
        self::requireNotNegative($items, 'The items');
        if ($discount < 0 || $discount > $items) {
            throw new LogicException("A discount of {$discount} kopecks is outside 0 to the items of {$items}");
        }

        return $items - $discount;
    }

    /**
     * @param string $amount Nonnegative decimal kopecks without leading zeroes
     * @param string $addend Nonnegative decimal kopecks without leading zeroes
     * @return string Their exact sum as decimal kopecks
     */
    private static function addDecimal(string $amount, string $addend): string
    {
        $left = strlen($amount) - 1;
        $right = strlen($addend) - 1;
        $carry = 0;
        $reversed = '';
        while ($left >= 0 || $right >= 0 || $carry !== 0) {
            $digits = $carry + ($left >= 0 ? (int) $amount[$left] : 0) + ($right >= 0 ? (int) $addend[$right] : 0);
            $reversed .= (string) ($digits % self::DECIMAL_BASE);
            $carry = intdiv($digits, self::DECIMAL_BASE);
            --$left;
            --$right;
        }

        return strrev($reversed);
    }

    /**
     * @param int $value Number that may not be below zero
     * @param string $what What the number is, to start the message with
     * @throws LogicException When the number is below zero
     */
    private static function requireNotNegative(int $value, string $what): void
    {
        if ($value < 0) {
            throw new LogicException("{$what} of {$value} is below zero");
        }
    }
}

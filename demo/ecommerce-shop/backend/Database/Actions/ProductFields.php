<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions;

use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Money\Money;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\HilosException;

/**
 * ProductFields - the one place the fields of a product are checked.
 *
 * Creating a product and editing one take the same name, description, price, category and a
 * picture, so both read them through here, and both derive the address from the name here.
 * The length limits are those of the columns; the price ceiling is the shop's.
 *
 * The address and the picture are checked against every product and every address, which
 * only the process holding the whole set can do: anywhere else the walk is refused.
 */
final class ProductFields
{
    public const int NAME_MAX_LENGTH = 100;
    public const int DESCRIPTION_MAX_LENGTH = 1000;

    /** Highest price, in kopecks: ₴999,999.99. */
    public const int PRICE_MAX = 99_999_999;

    /**
     * Checks a product name and returns it trimmed.
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
            throw new EmptyValueException('Product name cannot be empty');
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new ValueTooLongException('Product name exceeds maximum length of ' . self::NAME_MAX_LENGTH . ' characters');
        }

        return $name;
    }

    /**
     * Checks a product description and returns it trimmed.
     *
     * @param string $value Description as entered
     * @return string The trimmed description
     * @throws EmptyValueException When nothing but spaces is left
     * @throws ValueTooLongException When the description is longer than the column
     */
    public static function description(string $value): string
    {
        $description = trim($value);
        if ($description === '') {
            throw new EmptyValueException('Product description cannot be empty');
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw new ValueTooLongException(
                'Product description exceeds maximum length of ' . self::DESCRIPTION_MAX_LENGTH . ' characters',
            );
        }

        return $description;
    }

    /**
     * Checks a product price and returns it.
     *
     * @param int $value Price in kopecks
     * @return int The same price
     * @throws ValidationException When the price is not above zero or above the ceiling
     * @throws LogicException When the ceiling cannot be printed
     */
    public static function price(int $value): int
    {
        if ($value <= 0) {
            throw new ValidationException('Price must be greater than zero');
        }
        if ($value > self::PRICE_MAX) {
            throw new ValidationException('Price cannot be more than ' . Money::format(self::PRICE_MAX));
        }

        return $value;
    }

    /**
     * Checks that a category is chosen and still exists, and returns its id.
     *
     * @param ?int $categoryId Id of the chosen category, null when none is chosen
     * @return int The same id
     * @throws EmptyValueException When no category is chosen
     * @throws ValidationException When the category has been deleted
     * @throws HilosException When the categories cannot be read
     */
    public static function category(?int $categoryId): int
    {
        if ($categoryId === null) {
            throw new EmptyValueException('Choose a category for the product');
        }
        if (!isset(Hilos::$db->categories[$categoryId])) {
            throw new ValidationException('This category no longer exists');
        }

        return $categoryId;
    }

    /**
     * Gives a product of this name its address.
     *
     * Every address any product has ever had is read from the addresses collection, so an old
     * address never leads to another product.
     *
     * @param string $name Name checked by {@see self::name()}
     * @param ?int $productId Id of the product, null for one not saved yet
     * @return string The address of the name, or the first free numbered one
     * @throws LogicException When the address of the name cannot be built
     * @throws CollectionNotFullyLoadedException When this process does not hold every address
     */
    public static function slug(string $name, ?int $productId): string
    {
        $holders = [];
        foreach (Hilos::$db->productSlugs as $address) {
            $holders[$address->slug] = $address->productId;
        }

        return CatalogSlug::pick(CatalogSlug::fromName($name, 'product'), $holders, $productId);
    }
}

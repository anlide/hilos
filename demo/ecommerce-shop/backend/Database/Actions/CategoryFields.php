<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions;

use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValueTooLongException;

/**
 * CategoryFields - the one place the fields of a category are checked.
 *
 * Creating a category and editing one take the same name, the same description and a picture,
 * so both read them through here, and both derive the address from the name here. The limits
 * are those of the columns.
 */
final class CategoryFields
{
    public const int NAME_MAX_LENGTH = 100;
    public const int DESCRIPTION_MAX_LENGTH = 255;

    /**
     * Checks a category name and returns it trimmed.
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
            throw new EmptyValueException('Category name cannot be empty');
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new ValueTooLongException('Category name exceeds maximum length of ' . self::NAME_MAX_LENGTH . ' characters');
        }

        return $name;
    }

    /**
     * Checks a category description and returns it trimmed.
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
            throw new EmptyValueException('Category description cannot be empty');
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw new ValueTooLongException(
                'Category description exceeds maximum length of ' . self::DESCRIPTION_MAX_LENGTH . ' characters',
            );
        }

        return $description;
    }

    /**
     * Gives a category of this name its address.
     *
     * Every address any category has ever had is read from the addresses collection, out of use
     * ones too, so an old address never leads to another category.
     *
     * @param string $name Name checked by {@see self::name()}
     * @param ?int $categoryId Id of the category, null for one not saved yet
     * @return string The address of the name, or the first free numbered one
     * @throws LogicException When the address of the name cannot be built
     */
    public static function slug(string $name, ?int $categoryId): string
    {
        $holders = [];
        foreach (Hilos::$db->categorySlugs as $address) {
            $holders[$address->slug] = $address->categoryId;
        }

        return CatalogSlug::pick(CatalogSlug::fromName($name, 'category'), $holders, $categoryId);
    }
}

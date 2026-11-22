<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Hilos\Files\FileVisibility;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Writes the rows of the catalog straight into their tables past their libraries, with no file on disk.
 *
 * Warehouses, products with their categories and pictures, and the stock a warehouse keeps of a
 * product; call it inside the rollback scope, which takes the rows away again.
 */
trait CatalogFixtureRows
{
    protected const string WAREHOUSE_ADDRESS = 'Volodymyrska St, 1';

    /**
     * Writes a warehouse row past the warehouses library.
     *
     * @param ?string $name Name of the warehouse, or null for a name of its own
     * @return int Id of the new warehouse
     */
    protected static function warehouse(?string $name = null): int
    {
        Database::sql(
            'INSERT INTO warehouse (name, address) VALUES (?, ?)',
            [$name ?? 'Warehouse ' . RandomHelper::hex(8), self::WAREHOUSE_ADDRESS],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a product row of the given kind past the products library.
     *
     * @param ProductKind $kind Kind of the product
     * @param ?int $categoryId Category to put it in, or null for a category of its own
     * @return int Id of the new product
     */
    protected static function product(ProductKind $kind, ?int $categoryId = null): int
    {
        $slug = 'product-' . RandomHelper::hex(8);
        Database::sql(
            'INSERT INTO product (name, category_id, kind, price, description, picture_file_id, slug, changed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$slug, $categoryId ?? self::category(), $kind->value, 12_000, 'Product of the case', self::pictureFile(), $slug, 1000],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a category row a product may be put in, with its own picture, past the categories library.
     *
     * @param ?string $name Name of the category, or null for its address
     * @return int Id of the new category
     */
    protected static function category(?string $name = null): int
    {
        $slug = 'category-' . RandomHelper::hex(8);
        $position = (int)Database::sql('SELECT COALESCE(MAX(position), 0) + 1 AS next FROM category')->field('next');
        Database::sql(
            'INSERT INTO category (name, description, slug, position, picture_file_id) VALUES (?, ?, ?, ?, ?)',
            [$name ?? $slug, 'Flowers of the case', $slug, $position, self::pictureFile()],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a registry row a product or a category may show, with no file on disk behind it.
     *
     * @return int Id of the new registry row
     */
    protected static function pictureFile(): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, visibility, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$storedName, 'vase.webp', 'image/webp', 4, hash('sha256', $storedName), FileVisibility::PUBLIC->value, 1000],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a stock row past the stock library.
     *
     * @param int $productId Product the units are of
     * @param int $warehouseId Warehouse that keeps them
     * @param int $units How many, one at least
     */
    protected static function stock(int $productId, int $warehouseId, int $units): void
    {
        Database::sql(
            'INSERT INTO product_warehouse_stock (product_id, warehouse_id, units) VALUES (?, ?, ?)',
            [$productId, $warehouseId, $units],
        );
    }
}

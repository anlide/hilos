<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Database\Database;

/**
 * Starts a case of the catalog from an empty catalog, inside its rollback scope.
 *
 * The test database carries the Hilos Flowers seed, while the catalog cases count on empty
 * tables: a warehouse or a category of the same name, the first position, the whole list of
 * addresses. The rows go in the order of the references — first the carts of the guests and
 * of the accounts, which name a product, then the stock, the products and their addresses, the
 * categories and theirs, the warehouses — and the rollback of the scope brings the seed back.
 */
trait EmptyCatalog
{
    /** Deletes the catalog's rows and the carts that name its products; call it inside the rollback scope. */
    protected static function emptyCatalog(): void
    {
        Database::sqlRun('DELETE FROM session_product_cart');
        Database::sqlRun('DELETE FROM user_product_cart');
        Database::sqlRun('DELETE FROM product_warehouse_stock');
        Database::sqlRun('DELETE FROM product_slug');
        Database::sqlRun('DELETE FROM product');
        Database::sqlRun('DELETE FROM category_slug');
        Database::sqlRun('DELETE FROM category');
        Database::sqlRun('DELETE FROM warehouse');
    }
}

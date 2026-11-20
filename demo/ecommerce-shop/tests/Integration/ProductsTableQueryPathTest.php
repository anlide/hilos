<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\DTO\ProductStockTotals;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Tables\Product\ProductsTable;
use Demo\EcommerceShop\Tables\Product\ProductTableRow;
use Hilos\Files\FileVisibility;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The products table in a process that holds the products and not their stock: the totals come from a query.
 *
 * The case runs in a process of its own. A stock library started anywhere earlier in a process
 * leaves its collection promised whole for the rest of it, and the forgetting the tests have keeps
 * that promise, so in a shared process the stock would be read whole and the query never asked.
 * Only the products library starts here, and the stock is rows written straight into its table.
 */
#[IntegrationTest]
final class ProductsTableQueryPathTest extends ProductsLibraryEnvironment
{
    /** The totals are found by a query on the products, not read whole, and the table shows them as the holder of the stock would. */
    #[RunInSeparateProcess]
    public function testTheTotalsComeFromAQueryWhereTheStockIsNotHeld(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category();
        $kyiv = self::warehouse();
        $lviv = self::warehouse("{$tag} Lviv");
        $bulbs = Hilos::$db->products->actions->create("{$tag} Tulip bulbs", $roses, ProductKind::STOCK, 12_000, 'Bulbs', self::pictureFile())->id;
        $vase = Hilos::$db->products->actions->create("{$tag} Blue vase", $roses, ProductKind::UNIQUE, 450_000, 'Vase', self::pictureFile())->id;
        $seeds = Hilos::$db->products->actions->create("{$tag} Seeds", $roses, ProductKind::STOCK, 3_000, 'Seeds', self::pictureFile())->id;
        Database::sql(
            'INSERT INTO product_warehouse_stock (product_id, warehouse_id, units) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)',
            [$bulbs, $kyiv, 4, $bulbs, $lviv, 3, $vase, $lviv, 1],
        );
        $stock = Hilos::$db->productWarehouseStocks->getObjectCollection();
        self::assertNotNull($stock);
        self::assertFalse($stock->isAllLoaded());

        $totals = Hilos::$db->productWarehouseStocks->totalsOfProducts($bulbs, $vase, $seeds);

        self::assertFalse($stock->isAllLoaded());
        self::assertCount(3, $totals);
        self::assertEquals(new ProductStockTotals(7, null), $totals->of($bulbs));
        self::assertEquals(new ProductStockTotals(1, $lviv), $totals->of($vase));
        self::assertEquals(new ProductStockTotals(0, null), $totals->of($seeds));
        self::assertCount(0, Hilos::$db->productWarehouseStocks->totalsOfProducts());

        $table = new ProductsTable();
        $bulbsRow = $table->findRow($bulbs);
        $vaseRow = $table->findRow($vase);
        self::assertInstanceOf(ProductTableRow::class, $bulbsRow);
        self::assertInstanceOf(ProductTableRow::class, $vaseRow);
        self::assertSame([7, null, ProductAvailability::IN_STOCK->value], [$bulbsRow->units, $bulbsRow->warehouseName, $bulbsRow->availability]);
        self::assertSame([1, "{$tag} Lviv", ProductAvailability::FREE->value], [$vaseRow->units, $vaseRow->warehouseName, $vaseRow->availability]);
    }

    /**
     * Writes a category row a product may be put in, with its own picture, past the categories library.
     *
     * @return int Id of the new category
     */
    private static function category(): int
    {
        $slug = 'category-' . RandomHelper::hex(8);
        $position = (int)Database::sql('SELECT COALESCE(MAX(position), 0) + 1 AS next FROM category')->field('next');
        Database::sql(
            'INSERT INTO category (name, description, slug, position, picture_file_id) VALUES (?, ?, ?, ?, ?)',
            [$slug, 'Flowers of the case', $slug, $position, self::pictureFile()],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a warehouse row past the warehouses library.
     *
     * @param ?string $name Name of the warehouse, or null for a name of its own
     * @return int Id of the new warehouse
     */
    private static function warehouse(?string $name = null): int
    {
        Database::sql(
            'INSERT INTO warehouse (name, address) VALUES (?, ?)',
            [$name ?? 'Warehouse ' . RandomHelper::hex(8), 'Volodymyrska St, 1'],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a registry row a product or a category may show, with no file on disk behind it.
     *
     * @return int Id of the new registry row
     */
    private static function pictureFile(): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, visibility, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$storedName, 'vase.webp', 'image/webp', 4, hash('sha256', $storedName), FileVisibility::PUBLIC->value, 1000],
        );

        return Database::lastInsertId();
    }
}

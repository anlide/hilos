<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\CatalogSlug;
use Demo\EcommerceShop\Database\Actions\ProductFields;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Files\ContentHash;
use Hilos\Files\FileVisibility;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;

/** Mounts the shop and opens the files door before the managed rollback scope; no library, nothing written. */
abstract class HilosFlowersSeedEnvironment extends RollbackIntegrationTestCase
{
    /** Uses the migrated and seeded test database without taking over an already mounted project context. */
    protected function prepareIntegrationEnvironment(): void
    {
        Database::initialize(initHilos: Hilos::$db === null);
        Hilos::initFiles();
    }
}

/**
 * The Hilos Flowers catalog that Migration/Seed/001_hilos_flowers.sql leaves after a database reset, with its pictures.
 *
 * The rows are found by the names of the seed rather than by counting the tables, and read past the ORM; the case
 * writes nothing, so the rollback scope only guards the shared database. The pictures are read from the files
 * storage the seed command put them in.
 */
#[IntegrationTest]
final class HilosFlowersSeedTest extends HilosFlowersSeedEnvironment
{
    /** Warehouses of the seed, to their addresses. */
    private const array WAREHOUSES = [
        'Kyiv · Podil' => 'Kyiv, 7 Kostiantynivska St',
        'Lviv · Sykhiv' => 'Lviv, 30 Chervonoi Kalyny Ave',
        'Odesa · Peresyp' => 'Odesa, 4 Chornomorska Dorohy St',
    ];

    /** Categories of the seed in catalog order, to the names of their five products. */
    private const array CATALOG = [
        'Bouquets' => [
            'Morning meadow',
            'White peonies, 15 stems',
            'Autumn rowan',
            'Small bunch of chamomile',
            'Lavender bunch, dried',
        ],
        'Potted plants' => [
            'Monstera deliciosa, 80 cm',
            'Phalaenopsis, white, 2 spikes',
            'Olive tree, 1.2 m',
            'Echeveria, 6 cm',
            'Snake plant, 40 cm',
        ],
        'Pots & planters' => [
            'Ceramic pot 18 cm, sand',
            'Clay pot 12 cm',
            'Glazed pot 16 cm',
            'Hanging planter, cotton cord',
            'Hand-thrown planter, 30 cm',
        ],
        'Soil & bark' => [
            'Pine bark 5 L',
            'Universal soil mix 10 L',
            'Cactus soil 3 L',
            'Expanded clay drainage 2 L',
            'Three-year compost, 40 L sack',
        ],
        'Fertilizers' => [
            'Orchid fertilizer 250 ml',
            'Flowering plant feed 500 ml',
            'Green plant feed 500 ml',
            'Slow-release pellets 300 g',
            'Worm castings, farm batch 5 kg',
        ],
        'Care & tools' => [
            'Pruning scissors',
            'Mist sprayer 500 ml',
            'Watering can 1.5 L',
            'Plant labels, 50 pcs',
            'Vintage brass watering can',
        ],
    ];

    /** Each of the three warehouses stands at its own address. */
    public function testTheThreeWarehousesStandAtTheirAddresses(): void
    {
        foreach (self::WAREHOUSES as $name => $address) {
            self::assertSame(
                [['address' => $address]],
                Database::sql('SELECT address FROM warehouse WHERE name = ?', [$name])->rows(),
                $name,
            );
        }
    }

    /** The six categories follow one another in catalog order, each under the address of its name, recorded once. */
    public function testTheSixCategoriesFollowInOrderEachUnderTheAddressOfItsName(): void
    {
        $previousPosition = 0;
        foreach (array_keys(self::CATALOG) as $name) {
            $category = self::category($name);
            self::assertGreaterThan($previousPosition, (int)$category['position'], $name);
            $previousPosition = (int)$category['position'];
            self::assertSame(CatalogSlug::fromName($name, 'category'), $category['slug'], $name);
            self::assertSame(
                [['slug' => $category['slug']]],
                Database::sql('SELECT slug FROM category_slug WHERE category_id = ?', [(int)$category['id']])->rows(),
                $name,
            );
        }
    }

    /**
     * Each category holds its five products, of both kinds; each product lives under the address of its name, recorded
     * once, at a price the product form accepts, and no two were changed at the same moment.
     */
    public function testEachCategoryHoldsItsFiveProductsOfBothKindsUnderTheAddressesOfTheirNames(): void
    {
        $changedAt = [];
        foreach (self::CATALOG as $categoryName => $productNames) {
            $products = Database::sql(
                'SELECT id, name, kind, price, slug, changed_at FROM product WHERE category_id = ? ORDER BY id',
                [(int)self::category($categoryName)['id']],
            )->rows();
            self::assertSame($productNames, array_column($products, 'name'), $categoryName);
            self::assertEqualsCanonicalizing(
                [ProductKind::UNIQUE->value, ProductKind::STOCK->value],
                array_values(array_unique(array_column($products, 'kind'))),
                $categoryName,
            );
            foreach ($products as $product) {
                self::assertSame(CatalogSlug::fromName($product['name'], 'product'), $product['slug'], $product['name']);
                self::assertSame(
                    [['slug' => $product['slug']]],
                    Database::sql('SELECT slug FROM product_slug WHERE product_id = ?', [(int)$product['id']])->rows(),
                    $product['name'],
                );
                self::assertGreaterThan(0, (int)$product['price'], $product['name']);
                self::assertLessThanOrEqual(ProductFields::PRICE_MAX, (int)$product['price'], $product['name']);
                $changedAt[] = (int)$product['changed_at'];
            }
        }
        self::assertCount(count($changedAt), array_unique($changedAt));
    }

    /** A unique piece is one row of one unit; some stock is down to a few units, and some has none at all. */
    public function testEveryUniquePieceIsOneUnitWhileSomeStockIsLowAndSomeIsOut(): void
    {
        $low = [];
        $out = [];
        foreach (self::CATALOG as $productNames) {
            foreach ($productNames as $name) {
                $product = Database::sql('SELECT id, kind FROM product WHERE name = ?', [$name])->rows()[0];
                $units = array_map(
                    intval(...),
                    array_column(
                        Database::sql('SELECT units FROM product_warehouse_stock WHERE product_id = ?', [(int)$product['id']])->rows(),
                        'units',
                    ),
                );
                if ($product['kind'] === ProductKind::UNIQUE->value) {
                    self::assertSame([1], $units, $name);
                } elseif ($units === []) {
                    $out[] = $name;
                } elseif (array_sum($units) <= 5) {
                    $low[] = $name;
                }
            }
        }
        self::assertNotSame([], $low);
        self::assertNotSame([], $out);
    }

    /**
     * Every category and product shows a picture of its own: a public PNG bound to the catalog and owned by nobody,
     * kept in the files storage with the size and the hash of its row, within the weight of a seed picture.
     */
    public function testEveryPictureIsAPublicBoundPngOfItsOwnKeptInTheStorage(): void
    {
        $pictures = Database::sql(
            'SELECT f.stored_name, f.mime_type, f.size, f.content_hash, f.owner_user_id, f.visibility, f.bound '
            . 'FROM category c JOIN hilos_file f ON f.id = c.picture_file_id WHERE c.name IN (' . self::placeholders(self::CATALOG)
            . ') UNION ALL '
            . 'SELECT f.stored_name, f.mime_type, f.size, f.content_hash, f.owner_user_id, f.visibility, f.bound '
            . 'FROM product p JOIN hilos_file f ON f.id = p.picture_file_id WHERE p.name IN ('
            . self::placeholders(array_merge(...array_values(self::CATALOG))) . ')',
            [...array_keys(self::CATALOG), ...array_merge(...array_values(self::CATALOG))],
        )->rows();
        $storage = (Hilos::$files ?? self::fail('The files door is not open'))->storage;

        self::assertCount(36, $pictures);
        $total = 0;
        foreach ($pictures as $picture) {
            $name = $picture['stored_name'];
            self::assertSame('image/png', $picture['mime_type'], $name);
            self::assertNull($picture['owner_user_id'], $name);
            self::assertSame(FileVisibility::PUBLIC->value, $picture['visibility'], $name);
            self::assertSame(1, (int)$picture['bound'], $name);
            self::assertSame((int)$picture['size'], $storage->size($name), $name);
            $bytes = $storage->read($name);
            self::assertSame($picture['content_hash'], hash(ContentHash::ALGORITHM, $bytes), $name);
            self::assertLessThanOrEqual(20 * 1024, strlen($bytes), $name);
            $total += strlen($bytes);
        }
        self::assertCount(36, array_unique(array_column($pictures, 'content_hash')));
        self::assertLessThanOrEqual(1024 * 1024, $total);
    }

    /**
     * @param string $name Name of a category of the seed
     * @return array<string, mixed> Its id, position and address
     */
    private static function category(string $name): array
    {
        $rows = Database::sql('SELECT id, position, slug FROM category WHERE name = ?', [$name])->rows();
        self::assertCount(1, $rows, $name);

        return $rows[0];
    }

    /**
     * @param array<array-key, mixed> $values Values bound to an IN list
     * @return string One placeholder per value, comma-separated
     */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}

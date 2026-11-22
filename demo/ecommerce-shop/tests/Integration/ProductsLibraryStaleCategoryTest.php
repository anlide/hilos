<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Exception\ValidationException;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;

/**
 * A category deleted after the products library read it: the database's reference refuses the product.
 *
 * Each case writes its categories straight into their table before the first read, since the
 * categories are read whole on their first read; it reads them, then deletes a category past the
 * ORM, so the categories in memory still hold it. Such a category has no address, which would
 * otherwise keep it from being deleted. That leaves the loaded memory stale on purpose, and the
 * case asks for a cold reset after its rollback.
 */
#[IntegrationTest]
final class ProductsLibraryStaleCategoryTest extends ProductsLibraryEnvironment implements BreaksOrmState
{
    use CatalogFixtureRows;

    /** A new product put in a category deleted after it was read is refused, and no product or address is added. */
    public function testCreatingAProductInADeletedCategoryIsRefused(): void
    {
        $roses = self::staleCategory();
        $picture = self::pictureFile();
        $before = self::storedCount();

        self::assertRefused(
            'This category no longer exists',
            static fn () => Hilos::$db->products->actions->create('Blue vase', $roses, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', $picture),
        );

        self::assertSame($before, self::storedCount());
        self::assertSame([], self::storedAddresses());
    }

    /** A product moved into a category deleted after it was read is refused, and stays in its category, stored and in memory. */
    public function testMovingAProductIntoADeletedCategoryIsRefusedAndKeepsItWhereItWas(): void
    {
        $tulips = self::category();
        $roses = self::staleCategory();
        $id = Hilos::$db->products->actions->create('Blue vase', $tulips, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', self::pictureFile())->id;
        self::assertNotNull($id);
        $stored = self::storedRow($id);

        self::assertRefused(
            'This category no longer exists',
            static fn () => Hilos::$db->products[$id]->actions->update('Blue vase', $roses, ProductKind::UNIQUE, 450_000, 'Hand-thrown vase', null),
        );

        self::assertSame($tulips, $stored['categoryId']);
        self::assertUnchanged($id, $stored);
        self::assertSame(['blue-vase' => $id], self::storedAddresses());
    }

    /**
     * Writes a category, reads the categories whole, then deletes it past the ORM.
     *
     * @return int Id of the category the categories in memory still hold
     */
    private static function staleCategory(): int
    {
        $id = self::category();
        self::assertTrue(isset(Hilos::$db->categories[$id]));
        Database::sql('DELETE FROM category WHERE id = ?', [$id]);
        self::assertTrue(isset(Hilos::$db->categories[$id]));

        return $id;
    }

    /**
     * Checks a refused write left the product of its case as it was: the row and the collection.
     *
     * @param int $id Product id
     * @param array{name: string, categoryId: int, kind: string, price: int, description: string, pictureFileId: int, slug: string,
     *     changedAt: int} $stored The row read before the refused write
     */
    private static function assertUnchanged(int $id, array $stored): void
    {
        self::assertSame($stored, self::storedRow($id));
        $product = Hilos::$db->products[$id];
        self::assertSame($stored, [
            'name' => $product->name,
            'categoryId' => $product->categoryId,
            'kind' => $product->kind->value,
            'price' => $product->price,
            'description' => $product->description,
            'pictureFileId' => $product->pictureFileId,
            'slug' => $product->slug,
            'changedAt' => $product->changedAt,
        ]);
    }

    /**
     * Runs a write that must be refused, and checks the refusal reaches the person word for word.
     *
     * @param string $sentence The sentence the person is shown
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefused(string $sentence, callable $write): void
    {
        try {
            $write();
        } catch (ValidationException $e) {
            self::assertSame(ValidationException::class, $e::class);
            self::assertSame($sentence, $e->getMessage());
            self::assertSame($sentence, ActionFailureReason::forClient($e));

            return;
        }

        self::fail("Expected ValidationException: {$sentence}");
    }

    /**
     * @param int $id Product id
     * @return array{name: string, categoryId: int, kind: string, price: int, description: string, pictureFileId: int, slug: string,
     *     changedAt: int} The stored product read past the ORM
     */
    private static function storedRow(int $id): array
    {
        $rows = Database::sql(
            'SELECT name, category_id, kind, price, description, picture_file_id, slug, changed_at FROM product WHERE id = ?',
            [$id],
        )->rows();
        self::assertCount(1, $rows);

        return [
            'name' => (string)$rows[0]['name'],
            'categoryId' => (int)$rows[0]['category_id'],
            'kind' => (string)$rows[0]['kind'],
            'price' => (int)$rows[0]['price'],
            'description' => (string)$rows[0]['description'],
            'pictureFileId' => (int)$rows[0]['picture_file_id'],
            'slug' => (string)$rows[0]['slug'],
            'changedAt' => (int)$rows[0]['changed_at'],
        ];
    }

    /**
     * @return array<string, int> Every stored product address, in the order it was recorded, to its product
     */
    private static function storedAddresses(): array
    {
        $addresses = [];
        foreach (Database::sql('SELECT slug, product_id FROM product_slug ORDER BY id')->rows() as $row) {
            $addresses[(string)$row['slug']] = (int)$row['product_id'];
        }

        return $addresses;
    }

    /**
     * @return int Number of stored products, read past the ORM
     */
    private static function storedCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM product')->field('stored');
    }
}

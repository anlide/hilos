<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Exception\ValidationException;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;

/**
 * A warehouse deleted after the stock library read it: the database's reference refuses the stock row.
 *
 * Each case writes its warehouses and products straight into their tables before the first read,
 * since the warehouses are read whole on their first read; it reads them, then deletes a warehouse
 * past the ORM, so the warehouses in memory still hold it. That leaves the loaded memory stale on
 * purpose, and the case asks for a cold reset after its rollback.
 */
#[IntegrationTest]
final class StockLibraryStaleWarehouseTest extends StockLibraryEnvironment implements BreaksOrmState
{
    /** Units of a stock item set in a warehouse deleted after it was read are refused, and no row is added. */
    public function testUnitsInADeletedWarehouseAreRefused(): void
    {
        $bulbs = self::product(ProductKind::STOCK);
        $kyiv = self::staleWarehouse();
        $before = self::storedCount();

        self::assertRefused(
            'This warehouse no longer exists',
            static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 12),
        );

        self::assertSame($before, self::storedCount());
        self::assertSame([], self::storedRows($bulbs));
        self::assertSame([], self::loadedWarehouses($bulbs));
    }

    /** A new unique piece put into a warehouse deleted after it was read is refused, and no row is added. */
    public function testANewPieceInADeletedWarehouseIsRefused(): void
    {
        $vase = self::product(ProductKind::UNIQUE);
        $kyiv = self::staleWarehouse();
        $before = self::storedCount();

        self::assertRefused(
            'This warehouse no longer exists',
            static fn () => Hilos::$db->productWarehouseStocks->actions->place($vase, $kyiv),
        );

        self::assertSame($before, self::storedCount());
        self::assertSame([], self::storedRows($vase));
        self::assertSame([], self::loadedWarehouses($vase));
    }

    /** A unique piece moved into a warehouse deleted after it was read is refused, and stays where it was kept. */
    public function testMovingAPieceIntoADeletedWarehouseIsRefusedAndKeepsItWhereItWas(): void
    {
        $vase = self::product(ProductKind::UNIQUE);
        $kyiv = self::warehouse();
        $lviv = self::staleWarehouse();
        Hilos::$db->productWarehouseStocks->actions->place($vase, $kyiv);
        $stored = self::storedRows($vase);

        self::assertRefused(
            'This warehouse no longer exists',
            static fn () => Hilos::$db->productWarehouseStocks->actions->place($vase, $lviv),
        );

        self::assertSame($stored, self::storedRows($vase));
        self::assertSame([$kyiv], array_column($stored, 'warehouseId'));
        self::assertSame([$kyiv], self::loadedWarehouses($vase));
    }

    /**
     * Writes a warehouse, reads the warehouses whole, then deletes it past the ORM.
     *
     * @return int Id of the warehouse the warehouses in memory still hold
     */
    private static function staleWarehouse(): int
    {
        $id = self::warehouse();
        self::assertTrue(isset(Hilos::$db->warehouses[$id]));
        Database::sql('DELETE FROM warehouse WHERE id = ?', [$id]);
        self::assertTrue(isset(Hilos::$db->warehouses[$id]));

        return $id;
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
     * @param int $productId Product id
     * @return list<array{id: int, warehouseId: int, units: int}> The stock rows of the product read past the ORM, in the
     *     order they were added; empty when it has none
     */
    private static function storedRows(int $productId): array
    {
        $rows = [];
        foreach (Database::sql(
            'SELECT id, warehouse_id, units FROM product_warehouse_stock WHERE product_id = ? ORDER BY id',
            [$productId],
        )->rows() as $row) {
            $rows[] = ['id' => (int)$row['id'], 'warehouseId' => (int)$row['warehouse_id'], 'units' => (int)$row['units']];
        }

        return $rows;
    }

    /**
     * @param int $productId Product id
     * @return list<int> Warehouses of the product's stock rows as the stock in memory holds them
     */
    private static function loadedWarehouses(int $productId): array
    {
        $warehouses = [];
        foreach (Hilos::$db->productWarehouseStocks->whereColumnIs(ObjectProductWarehouseStock::productId, $productId) as $row) {
            $warehouses[] = $row->warehouseId;
        }

        return $warehouses;
    }

    /**
     * @return int Number of stored stock rows of every product, read past the ORM
     */
    private static function storedCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM product_warehouse_stock')->field('stored');
    }
}

<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\HilosException;
use Hilos\Testing\IntegrationTest;

/**
 * The real shop schema and the stock actions under their library's claim; the rollback scope proves writes, not commit.
 *
 * Warehouses, products of either kind, their categories and pictures are rows written straight
 * into their tables past their libraries, with no file on disk, and each case writes them before
 * its first action: the warehouses are read whole on their first read, the products may be too,
 * and the rollback forgets that read with the rows. The stock is read back past the ORM.
 */
#[IntegrationTest]
final class StockLibraryTest extends StockLibraryEnvironment
{
    /** The library's start declares the stock complete in its process. */
    public function testTheStartDeclaresTheStockComplete(): void
    {
        $stock = Hilos::$db->productWarehouseStocks->getObjectCollection();

        self::assertNotNull($stock);
        self::assertTrue($stock->isAllLoaded());
    }

    /** A stock item gets a row per warehouse: the first number adds it, another number changes that row, another warehouse adds one. */
    public function testTheUnitsOfAStockItemAddAWarehouseRowAndChangeIt(): void
    {
        $kyiv = self::warehouse();
        $lviv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 12);
        $rows = self::storedRows($bulbs);
        self::assertCount(1, $rows);
        self::assertSame(['warehouseId' => $kyiv, 'units' => 12], self::withoutId($rows[0]));
        $kyivRow = $rows[0]['id'];

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 30);
        self::assertSame([['id' => $kyivRow, 'warehouseId' => $kyiv, 'units' => 30]], self::storedRows($bulbs));

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $lviv, 5);
        $rows = self::storedRows($bulbs);
        self::assertCount(2, $rows);
        self::assertSame(['id' => $kyivRow, 'warehouseId' => $kyiv, 'units' => 30], $rows[0]);
        self::assertNotSame($kyivRow, $rows[1]['id']);
        self::assertSame(['warehouseId' => $lviv, 'units' => 5], self::withoutId($rows[1]));
    }

    /** The same number keeps the row and its id, zero takes the row away, and zero where there is no row writes nothing. */
    public function testZeroRemovesTheRowAndTheSameNumberKeepsIt(): void
    {
        $kyiv = self::warehouse();
        $lviv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 7);
        $rows = self::storedRows($bulbs);
        self::assertCount(1, $rows);

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 7);
        self::assertSame($rows, self::storedRows($bulbs));

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 0);
        self::assertSame([], self::storedRows($bulbs));

        $before = self::storedCount();
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $lviv, 0);
        self::assertSame([], self::storedRows($bulbs));
        self::assertSame($before, self::storedCount());
    }

    /** A unique piece is put in as one unit, moved by changing the warehouse of its one row, and left alone where it already is. */
    public function testAUniquePieceIsPutInMovedAndKeepsItsOneRow(): void
    {
        $kyiv = self::warehouse();
        $lviv = self::warehouse();
        $vase = self::product(ProductKind::UNIQUE);

        Hilos::$db->productWarehouseStocks->actions->place($vase, $kyiv);
        $rows = self::storedRows($vase);
        self::assertCount(1, $rows);
        self::assertSame(['warehouseId' => $kyiv, 'units' => 1], self::withoutId($rows[0]));
        $pieceRow = $rows[0]['id'];

        Hilos::$db->productWarehouseStocks->actions->place($vase, $lviv);
        self::assertSame([['id' => $pieceRow, 'warehouseId' => $lviv, 'units' => 1]], self::storedRows($vase));

        Hilos::$db->productWarehouseStocks->actions->place($vase, $lviv);
        self::assertSame([['id' => $pieceRow, 'warehouseId' => $lviv, 'units' => 1]], self::storedRows($vase));
    }

    /** Units set for a unique piece are refused, so it never gets a second row. */
    public function testASecondRowOfAUniquePieceIsRefused(): void
    {
        $kyiv = self::warehouse();
        $lviv = self::warehouse();
        $vase = self::product(ProductKind::UNIQUE);
        Hilos::$db->productWarehouseStocks->actions->place($vase, $kyiv);
        $stored = self::storedRows($vase);
        self::assertCount(1, $stored);

        self::assertRefused(
            ValidationException::class,
            'A unique piece has no units to set: it is kept in one warehouse',
            static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($vase, $lviv, 1),
        );

        self::assertSame($stored, self::storedRows($vase));
        self::assertSame($kyiv, $stored[0]['warehouseId']);
    }

    /** A stock item is not put into a warehouse as a piece, and nothing is stored. */
    public function testPlacingAStockItemIsRefused(): void
    {
        $kyiv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);

        self::assertRefused(
            ValidationException::class,
            'A stock item is not moved: set its units in each warehouse',
            static fn () => Hilos::$db->productWarehouseStocks->actions->place($bulbs, $kyiv),
        );

        self::assertSame([], self::storedRows($bulbs));
    }

    /** Units below zero or above 999,999 are refused, nothing new is stored, and the row already there keeps its number. */
    public function testUnitsBelowZeroOrAboveTheCeilingAreRefused(): void
    {
        $kyiv = self::warehouse();
        $lviv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 4);
        $stored = self::storedRows($bulbs);

        foreach ([$kyiv, $lviv] as $warehouse) {
            self::assertRefused(
                ValidationException::class,
                'Units cannot be negative',
                static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $warehouse, -1),
            );
            self::assertRefused(
                ValidationException::class,
                'Units cannot be more than 999,999',
                static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $warehouse, 1_000_000),
            );
        }

        self::assertSame($stored, self::storedRows($bulbs));
    }

    /** Either action refuses a product or a warehouse that does not exist, and nothing is stored. */
    public function testAnUnknownProductOrWarehouseIsRefusedByBothActions(): void
    {
        $kyiv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);
        $vase = self::product(ProductKind::UNIQUE);
        $noProduct = (int)Database::sql('SELECT MAX(id) + 1 AS absent FROM product')->field('absent');
        $noWarehouse = (int)Database::sql('SELECT MAX(id) + 1 AS absent FROM warehouse')->field('absent');
        $before = self::storedCount();

        self::assertRefused(
            ValidationException::class,
            'This product does not exist',
            static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($noProduct, $kyiv, 3),
        );
        self::assertRefused(
            ValidationException::class,
            'This product does not exist',
            static fn () => Hilos::$db->productWarehouseStocks->actions->place($noProduct, $kyiv),
        );
        self::assertRefused(
            ValidationException::class,
            'This warehouse no longer exists',
            static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $noWarehouse, 3),
        );
        self::assertRefused(
            ValidationException::class,
            'This warehouse no longer exists',
            static fn () => Hilos::$db->productWarehouseStocks->actions->place($vase, $noWarehouse),
        );

        self::assertSame($before, self::storedCount());
    }

    /** An agent other than the library may neither add a row nor change or remove one. */
    public function testAWriterOtherThanTheLibraryIsRefused(): void
    {
        $kyiv = self::warehouse();
        $lviv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 3);
        $stored = self::storedRows($bulbs);
        self::assertCount(1, $stored);

        self::assertRefusedToAnotherWriter(
            CreateNotAllowedException::class,
            static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $lviv, 2),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 9),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 0),
        );

        self::assertSame($stored, self::storedRows($bulbs));
    }

    /**
     * Runs a write that must be refused, and checks the refusal reaches the person word for word.
     *
     * @param class-string<ValidationException> $expected Exact refusal class
     * @param string $sentence The sentence the person is shown
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefused(string $expected, string $sentence, callable $write): void
    {
        try {
            $write();
        } catch (ValidationException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($sentence, $e->getMessage());
            self::assertSame($sentence, ActionFailureReason::forClient($e));

            return;
        }

        self::fail("Expected {$expected}: {$sentence}");
    }

    /**
     * Runs a write as the shop's app agent, which does not own the stock, and checks it is refused.
     *
     * @param class-string<HilosException> $expected Exact refusal class
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefusedToAnotherWriter(string $expected, callable $write): void
    {
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: AgentType::ECOMMERCE_SHOP), $write);
        } catch (HilosException $e) {
            self::assertSame($expected, $e::class);

            return;
        }

        self::fail("Expected {$expected} for a writer other than the stock library");
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
     * @param array{id: int, warehouseId: int, units: int} $row A stored stock row
     * @return array{warehouseId: int, units: int} The row without the id the database gave it
     */
    private static function withoutId(array $row): array
    {
        unset($row['id']);

        return $row;
    }

    /**
     * @return int Number of stored stock rows of every product, read past the ORM
     */
    private static function storedCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM product_warehouse_stock')->field('stored');
    }
}

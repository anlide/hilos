<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Entity\Item\Warehouse as EntityWarehouse;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Tables\ProductStock\ProductStockTable;
use Demo\EcommerceShop\Tables\ProductStock\ProductStockTableRow;
use Hilos\AdminViewMode\ViewerFields;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The stock table of one product over the real shop schema: a row for every warehouse with the units of the
 * window's product, its search and order, and the row a fact of a warehouse or of the product's stock redraws;
 * the rollback scope proves reads of written rows, not commit.
 *
 * Warehouses and products are rows written straight into their tables before the case reads any of them,
 * and the stock is written by its library under its frame, as the stock library's own test does. Each
 * fact is built in the shape the ORM announces it: a sync from another process names the stored columns,
 * a write of this process and a deletion name the fields of the row's Object.
 */
#[IntegrationTest]
final class ProductStockTableTest extends StockLibraryEnvironment
{
    /** The window of a stock item carries every warehouse by name with the units it keeps, zero where it keeps none. */
    public function testTheWindowOfAStockItemCarriesEveryWarehouseByName(): void
    {
        $tag = RandomHelper::hex(8);
        $lviv = self::warehouse("{$tag} Lviv");
        $odesa = self::warehouse("{$tag} Odesa");
        $kyiv = self::warehouse("{$tag} Kyiv");
        $bulbs = self::product(ProductKind::STOCK);
        $seeds = self::product(ProductKind::STOCK);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 12);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $lviv, 3);
        Hilos::$db->productWarehouseStocks->actions->setUnits($seeds, $odesa, 5);

        $table = new ProductStockTable();

        self::assertSame([
            self::row($kyiv, "{$tag} Kyiv", 12),
            self::row($lviv, "{$tag} Lviv", 3),
            self::row($odesa, "{$tag} Odesa", 0),
        ], self::payloads($table->getPage(self::window($bulbs, $tag, $table->defaultSort()))->rows));
        self::assertSame(self::storedWarehouseCount(), $table->getPage(self::window($bulbs))->totalCount);
    }

    /** A unique piece, a product that is not there, and a window that names no product are each an empty set. */
    public function testAPieceAnUnknownProductAndNoProductAreEmpty(): void
    {
        $kyiv = self::warehouse();
        $vase = self::product(ProductKind::UNIQUE);
        $gone = self::product(ProductKind::STOCK);
        Database::sql('DELETE FROM product WHERE id = ?', [$gone]);
        Hilos::$db->productWarehouseStocks->actions->place($vase, $kyiv);

        $table = new ProductStockTable();

        foreach ([$vase, $gone, null] as $product) {
            $window = $table->getPage(self::window($product));
            self::assertSame([], $window->rows, (string)$product);
            self::assertSame(0, $window->totalCount, (string)$product);
        }
    }

    /**
     * A search keeps the warehouses whose name holds the term, and the name orders the window both ways,
     * and an order by units falls back to the name.
     */
    public function testTheSearchAndTheOrderAreByTheWarehouseName(): void
    {
        $tag = RandomHelper::hex(8);
        $cedar = self::warehouse("Kyiv {$tag} Cedar");
        $amber = self::warehouse("Kyiv {$tag} Amber");
        $birch = self::warehouse("Lviv {$tag} Birch");
        $bulbs = self::product(ProductKind::STOCK);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $cedar, 9);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $amber, 5);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $birch, 1);
        $table = new ProductStockTable();

        self::assertSame([$amber, $cedar], self::keys($table, self::window($bulbs, "Kyiv {$tag}", $table->defaultSort())));
        self::assertSame([$birch], self::keys($table, self::window($bulbs, "{$tag} Bir", $table->defaultSort())));

        foreach ([TableConstants::ORDER_ASC => [$amber, $cedar, $birch], TableConstants::ORDER_DESC => [$birch, $cedar, $amber]] as $order => $keys) {
            self::assertSame($keys, self::keys($table, self::window($bulbs, $tag, self::sort(ProductStockTableRow::warehouseName, $order))), $order);
        }

        self::assertSame(
            [$amber, $cedar, $birch],
            self::keys($table, self::window($bulbs, $tag, self::sort(ProductStockTableRow::units, TableConstants::ORDER_ASC))),
        );
    }

    /** Units put in and changed for the window's product redraw the row of their warehouse; another product's window is untouched. */
    public function testUnitsOfTheWindowsProductRedrawTheRowOfTheirWarehouse(): void
    {
        $tag = RandomHelper::hex(8);
        $kyiv = self::warehouse("{$tag} Kyiv");
        $bulbs = self::product(ProductKind::STOCK);
        $seeds = self::product(ProductKind::STOCK);
        $table = new ProductStockTable();

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 4);
        $synced = self::storedStock($bulbs, $kyiv);
        $stockId = (string)$synced[EntityProductWarehouseStock::id];
        $created = SourceChange::dbCreated(EcommerceShopDbContext::productWarehouseStocks, $stockId, $synced);
        self::assertSame(
            [[TableMutationType::Update, $kyiv, self::row($kyiv, "{$tag} Kyiv", 4)]],
            self::described($table->buildMutationsForWindow($created, self::window($bulbs))),
        );
        self::assertSame([], $table->buildMutationsForWindow($created, self::window($seeds)));

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 9);
        $changed = SourceChange::dbUpdated(
            EcommerceShopDbContext::productWarehouseStocks,
            $stockId,
            [EntityProductWarehouseStock::units => 9],
            previous: [EntityProductWarehouseStock::units => 4],
        );
        self::assertSame(
            [[TableMutationType::Update, $kyiv, self::row($kyiv, "{$tag} Kyiv", 9)]],
            self::described($table->buildMutationsForWindow($changed, self::window($bulbs))),
        );
        self::assertSame([], $table->buildMutationsForWindow($changed, self::window($seeds)));
    }

    /** Units taken away to zero redraw the row of their warehouse with zero, and the row stays. */
    public function testUnitsTakenAwayToZeroUpdateTheRowRatherThanRemoveIt(): void
    {
        $tag = RandomHelper::hex(8);
        $kyiv = self::warehouse("{$tag} Kyiv");
        $bulbs = self::product(ProductKind::STOCK);
        $table = new ProductStockTable();

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 5);
        $stockId = (string)self::storedStock($bulbs, $kyiv)[EntityProductWarehouseStock::id];
        $announced = Hilos::$db->productWarehouseStocks[(int)$stockId]->toArray();
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 0);
        $takenAway = SourceChange::dbDeleted(EcommerceShopDbContext::productWarehouseStocks, $stockId, $announced);

        self::assertSame(
            [[TableMutationType::Update, $kyiv, self::row($kyiv, "{$tag} Kyiv", 0)]],
            self::described($table->buildMutationsForWindow($takenAway, self::window($bulbs))),
        );
        self::assertSame(
            [self::row($kyiv, "{$tag} Kyiv", 0)],
            self::payloads($table->getPage(self::window($bulbs, $tag))->rows),
        );
    }

    /** A created warehouse enters the window with zero units, a renamed one is redrawn under its new name, and a deleted one leaves. */
    public function testAWarehouseCreatedRenamedAndDeletedMovesItsRow(): void
    {
        $tag = RandomHelper::hex(8);
        $fresh = self::warehouse("{$tag} Fresh");
        $podil = self::warehouse("{$tag} Podil");
        $gone = self::warehouse("{$tag} Gone");
        $bulbs = self::product(ProductKind::STOCK);
        $vase = self::product(ProductKind::UNIQUE);
        Database::sql('UPDATE warehouse SET name = ? WHERE id = ?', ["{$tag} Rynok", $podil]);
        $goneRow = Database::sql('SELECT * FROM warehouse WHERE id = ?', [$gone])->rows()[0];
        Database::sql('DELETE FROM warehouse WHERE id = ?', [$gone]);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $podil, 2);
        $table = new ProductStockTable();

        $created = SourceChange::dbCreated(EcommerceShopDbContext::warehouses, (string)$fresh, [
            EntityWarehouse::id => $fresh,
            EntityWarehouse::name => "{$tag} Fresh",
            EntityWarehouse::address => self::WAREHOUSE_ADDRESS,
        ]);
        self::assertSame(
            [[TableMutationType::Create, $fresh, self::row($fresh, "{$tag} Fresh", 0)]],
            self::described($table->buildMutationsForWindow($created, self::window($bulbs))),
        );
        self::assertSame([], $table->buildMutationsForWindow($created, self::window($vase)));

        $renamed = SourceChange::dbUpdated(
            EcommerceShopDbContext::warehouses,
            (string)$podil,
            [EntityWarehouse::name => "{$tag} Rynok"],
            previous: [EntityWarehouse::name => "{$tag} Podil"],
        );
        self::assertSame(
            [[TableMutationType::Update, $podil, self::row($podil, "{$tag} Rynok", 2)]],
            self::described($table->buildMutationsForWindow($renamed, self::window($bulbs))),
        );

        $deleted = SourceChange::dbDeleted(EcommerceShopDbContext::warehouses, (string)$gone, $goneRow);
        self::assertSame(
            [[TableMutationType::Delete, $gone, null]],
            self::described($table->buildMutationsForWindow($deleted, self::window($bulbs))),
        );
    }

    /** The set answers for one warehouse under the window's search the way the window would, and a piece's set holds none. */
    public function testTheSetAnswersForOneWarehouseUnderTheSearch(): void
    {
        $tag = RandomHelper::hex(8);
        $kyiv = self::warehouse("{$tag} Kyiv");
        $lviv = self::warehouse("{$tag} Lviv");
        $bulbs = self::product(ProductKind::STOCK);
        $vase = self::product(ProductKind::UNIQUE);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $lviv, 3);
        Hilos::$db->productWarehouseStocks->actions->place($vase, $kyiv);
        $table = new ProductStockTable();

        $bulbsInKyiv = self::window($bulbs, "{$tag} Kyiv");
        self::assertTrue($table->containsRow($kyiv, $bulbsInKyiv));
        self::assertTrue($table->containsRow((string)$kyiv, $bulbsInKyiv));
        self::assertFalse($table->containsRow($lviv, $bulbsInKyiv));
        self::assertFalse($table->containsRow('kyiv', $bulbsInKyiv));
        self::assertTrue($table->containsRow($lviv, self::window($bulbs, $tag)));

        self::assertFalse($table->containsRow($kyiv, self::window($vase, "{$tag} Kyiv")));
    }

    /** Every field of a row comes from a column the shop's warehouse and stock entities hold not personal, so a viewer sees the whole row. */
    public function testAViewerOfTheAdminViewModeSeesTheWholeRow(): void
    {
        $tag = RandomHelper::hex(8);
        $kyiv = self::warehouse("{$tag} Kyiv");
        self::warehouse("{$tag} Lviv");
        $bulbs = self::product(ProductKind::STOCK);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 7);
        $notPersonal = [
            EcommerceShopDbContext::warehouses => EntityWarehouse::_piiNotPersonal,
            EcommerceShopDbContext::productWarehouseStocks => EntityProductWarehouseStock::_piiNotPersonal,
        ];
        $columnShown = static fn (string $collection, string $field): bool => in_array(
            Hilos::$db->{$collection}->columnForField($field),
            $notPersonal[$collection] ?? [],
            true,
        );

        $table = new ProductStockTable();
        $rows = self::payloads($table->getPage(self::window($bulbs, $tag))->rows);

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($row, ViewerFields::hide($row, $table->wireFields(), $columnShown));
        }
    }

    /**
     * @param ?int $productId Product the window is opened on, or null for a window that names none
     * @param ?string $search Search term
     * @param ?TableSortOrderDTO $sort Order of the window
     * @return TableQueryDTO The window of the product
     */
    private static function window(?int $productId, ?string $search = null, ?TableSortOrderDTO $sort = null): TableQueryDTO
    {
        return new TableQueryDTO(
            search: $search,
            sort: $sort,
            filter: $productId === null ? [] : [ProductStockTable::FILTER_PRODUCT => $productId],
        );
    }

    /**
     * @param string $field Field to order by
     * @param string $order Direction of the order
     * @return TableSortOrderDTO The order by that field
     */
    private static function sort(string $field, string $order): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO($field, $order));
    }

    /**
     * @param int $warehouseId Warehouse id
     * @param string $name Name the row should show
     * @param int $units Units the row should show
     * @return array<string, int|string> The row payload the table should carry for the warehouse
     */
    private static function row(int $warehouseId, string $name, int $units): array
    {
        return [
            ProductStockTableRow::warehouseId => $warehouseId,
            ProductStockTableRow::warehouseName => $name,
            ProductStockTableRow::units => $units,
        ];
    }

    /**
     * @param ProductStockTable $table Table to read
     * @param TableQueryDTO $query Window query
     * @return list<int|string> Row keys of the window, in its order
     */
    private static function keys(ProductStockTable $table, TableQueryDTO $query): array
    {
        return array_map(static fn (AbstractTableRow $row): int|string => $row->getRowKey(), $table->getPage($query)->rows);
    }

    /**
     * @param list<AbstractTableRow> $rows Rows of a window
     * @return list<array<string, mixed>> Their payloads, in the window's order
     */
    private static function payloads(array $rows): array
    {
        return array_map(static fn (AbstractTableRow $row): array => $row->toArray(), $rows);
    }

    /**
     * @param list<TableRowMutationDTO> $mutations Mutations a fact produced
     * @return list<array{TableMutationType, int|string, ?array<string, mixed>}> Each mutation's type, row key and row payload
     */
    private static function described(array $mutations): array
    {
        return array_map(
            static fn (TableRowMutationDTO $mutation): array => [$mutation->type, $mutation->rowKey, $mutation->row?->toArray()],
            $mutations,
        );
    }

    /**
     * @param int $productId Product id
     * @param int $warehouseId Warehouse id
     * @return array<string, int> The stock row of the product in the warehouse, read past the ORM in its stored columns,
     *     as a sync from another process announces it
     */
    private static function storedStock(int $productId, int $warehouseId): array
    {
        $rows = Database::sql(
            'SELECT * FROM product_warehouse_stock WHERE product_id = ? AND warehouse_id = ?',
            [$productId, $warehouseId],
        )->rows();
        self::assertCount(1, $rows);

        return [
            EntityProductWarehouseStock::id => (int)$rows[0][EntityProductWarehouseStock::id],
            EntityProductWarehouseStock::product_id => (int)$rows[0][EntityProductWarehouseStock::product_id],
            EntityProductWarehouseStock::warehouse_id => (int)$rows[0][EntityProductWarehouseStock::warehouse_id],
            EntityProductWarehouseStock::units => (int)$rows[0][EntityProductWarehouseStock::units],
        ];
    }

    /**
     * @return int Number of stored warehouses, read past the ORM
     */
    private static function storedWarehouseCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM warehouse')->field('stored');
    }
}

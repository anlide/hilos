<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Entity\Item\Warehouse as EntityWarehouse;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Tables\Warehouse\WarehousesTable;
use Demo\EcommerceShop\Tables\Warehouse\WarehouseTableRow;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The warehouses table over the real shop schema: its window with the stock totals, its search, and the row
 * a fact of a warehouse or of its stock redraws; the rollback scope proves reads of written rows, not commit.
 *
 * Warehouses and products are rows written straight into their tables, and the stock is written by
 * its library, as the stock library's own test does. The stock is not held, as in the warehouses page's
 * process, so the totals go by SQL on the warehouse column; a walk of the stock would be refused.
 * Each stock fact is built in the shape the ORM announces.
 */
#[IntegrationTest]
final class WarehousesTableTest extends WarehousesTableEnvironment
{
    /** Every warehouse is in the window with its totals, an empty one with none, ordered by name rather than by id. */
    public function testTheWindowCarriesEveryWarehouseWithItsTotalsInNameOrder(): void
    {
        $tag = RandomHelper::hex(8);
        $lviv = self::warehouse("{$tag} Lviv");
        $odesa = self::warehouse("{$tag} Odesa");
        $kyiv = self::warehouse("{$tag} Kyiv");
        $bulbs = self::product(ProductKind::STOCK);
        $seeds = self::product(ProductKind::STOCK);
        $vase = self::product(ProductKind::UNIQUE);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 12);
        Hilos::$db->productWarehouseStocks->actions->setUnits($seeds, $kyiv, 3);
        Hilos::$db->productWarehouseStocks->actions->place($vase, $lviv);

        $table = new WarehousesTable();
        $window = $table->getPage(new TableQueryDTO(sort: $table->defaultSort()));

        self::assertSame(self::storedWarehouseCount(), $window->totalCount);
        self::assertSame([
            self::row($kyiv, "{$tag} Kyiv", 2, 15),
            self::row($lviv, "{$tag} Lviv", 1, 1),
            self::row($odesa, "{$tag} Odesa", 0, 0),
        ], self::rowsOf($window->rows, [$lviv, $odesa, $kyiv]));
    }

    /** A search keeps the warehouses whose name holds the term, and the set answers for each warehouse the same way. */
    public function testASearchKeepsTheWarehousesWhoseNameHoldsTheTerm(): void
    {
        $tag = RandomHelper::hex(8);
        $kyiv = self::warehouse("{$tag} Kyiv");
        $lviv = self::warehouse("{$tag} Lviv");
        $bulbs = self::product(ProductKind::STOCK);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $lviv, 4);

        $table = new WarehousesTable();
        $query = new TableQueryDTO(search: "{$tag} Lv", sort: $table->defaultSort());
        $window = $table->getPage($query);

        self::assertSame(1, $window->totalCount);
        self::assertSame([self::row($lviv, "{$tag} Lviv", 1, 4)], array_map(
            static fn (AbstractTableRow $row): array => $row->toArray(),
            $window->rows,
        ));
        self::assertTrue($table->containsRow($lviv, $query));
        self::assertFalse($table->containsRow($kyiv, $query));
    }

    /** Units put into a warehouse redraw it with the new totals, and so does another number for the same row. */
    public function testUnitsPutInAndChangedRedrawTheirWarehouse(): void
    {
        $kyiv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);
        $table = new WarehousesTable();

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 12);
        $stored = self::announcedStock($bulbs, $kyiv);
        $created = $table->buildMutationsForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::productWarehouseStocks,
            (string)$stored[ObjectProductWarehouseStock::id],
            $stored,
        ));
        self::assertSame([[TableMutationType::Update, $kyiv, self::row($kyiv, self::storedName($kyiv), 1, 12)]], self::described($created));

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 30);
        $changed = $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::productWarehouseStocks,
            (string)$stored[ObjectProductWarehouseStock::id],
            [EntityProductWarehouseStock::units => 30],
            previous: [EntityProductWarehouseStock::units => 12],
        ));
        self::assertSame([[TableMutationType::Update, $kyiv, self::row($kyiv, self::storedName($kyiv), 1, 30)]], self::described($changed));
    }

    /** Zero takes the stock row away, and its warehouse, named only by the fact's row, is redrawn without it. */
    public function testZeroUnitsRedrawTheWarehouseTheRowLeft(): void
    {
        $kyiv = self::warehouse();
        $bulbs = self::product(ProductKind::STOCK);
        $seeds = self::product(ProductKind::STOCK);
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 7);
        Hilos::$db->productWarehouseStocks->actions->setUnits($seeds, $kyiv, 3);
        $stored = self::announcedStock($bulbs, $kyiv);

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 0);
        $mutations = (new WarehousesTable())->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::productWarehouseStocks,
            (string)$stored[ObjectProductWarehouseStock::id],
            $stored,
        ));

        self::assertSame([[TableMutationType::Update, $kyiv, self::row($kyiv, self::storedName($kyiv), 1, 3)]], self::described($mutations));
    }

    /** A unique piece moved to another warehouse redraws both: the one it left with less, the one it came to with more. */
    public function testAMovedPieceRedrawsTheWarehouseItLeftAndTheOneItCameTo(): void
    {
        $kyiv = self::warehouse();
        $lviv = self::warehouse();
        $vase = self::product(ProductKind::UNIQUE);
        $seeds = self::product(ProductKind::STOCK);
        Hilos::$db->productWarehouseStocks->actions->place($vase, $kyiv);
        Hilos::$db->productWarehouseStocks->actions->setUnits($seeds, $kyiv, 3);
        $stored = self::storedStock($vase, $kyiv);

        Hilos::$db->productWarehouseStocks->actions->place($vase, $lviv);
        $mutations = (new WarehousesTable())->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::productWarehouseStocks,
            (string)$stored['id'],
            [EntityProductWarehouseStock::warehouse_id => $lviv],
            previous: [EntityProductWarehouseStock::warehouse_id => $kyiv],
        ));

        $described = self::described($mutations);
        usort($described, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        self::assertSame([
            [TableMutationType::Update, $kyiv, self::row($kyiv, self::storedName($kyiv), 1, 3)],
            [TableMutationType::Update, $lviv, self::row($lviv, self::storedName($lviv), 1, 1)],
        ], $described);
    }

    /** A renamed warehouse is read again by its id and redrawn under the new name; a deleted one leaves the window. */
    public function testARenamedWarehouseIsRedrawnAndADeletedOneLeaves(): void
    {
        $tag = RandomHelper::hex(8);
        $podil = self::warehouse("{$tag} Podil");
        $gone = self::warehouse();
        $table = new WarehousesTable();

        Database::sql('UPDATE warehouse SET name = ? WHERE id = ?', ["{$tag} Rynok", $podil]);
        $renamed = $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::warehouses,
            (string)$podil,
            [EntityWarehouse::name => "{$tag} Rynok"],
            previous: [EntityWarehouse::name => "{$tag} Podil"],
        ));
        self::assertSame([[TableMutationType::Update, $podil, self::row($podil, "{$tag} Rynok", 0, 0)]], self::described($renamed));

        $goneRow = Database::sql('SELECT * FROM warehouse WHERE id = ?', [$gone])->rows()[0];
        Database::sql('DELETE FROM warehouse WHERE id = ?', [$gone]);
        $deleted = $table->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::warehouses,
            (string)$gone,
            $goneRow,
        ));
        self::assertSame([[TableMutationType::Delete, $gone, null]], self::described($deleted));
    }

    /** A created warehouse enters the window as a row of its own. */
    public function testACreatedWarehouseEntersTheWindowWithItsRow(): void
    {
        $tag = RandomHelper::hex(8);
        $podil = self::warehouse("{$tag} Podil");

        $mutations = (new WarehousesTable())->buildMutationsForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::warehouses,
            (string)$podil,
            [
                EntityWarehouse::id => $podil,
                EntityWarehouse::name => "{$tag} Podil",
                EntityWarehouse::address => self::WAREHOUSE_ADDRESS,
            ],
        ));

        self::assertSame([[TableMutationType::Create, $podil, self::row($podil, "{$tag} Podil", 0, 0)]], self::described($mutations));
    }

    /** A stock fact naming a warehouse that is gone redraws nothing and raises nothing. */
    public function testAStockFactNamingAVanishedWarehouseMutatesNothing(): void
    {
        $gone = self::warehouse();
        Database::sql('DELETE FROM warehouse WHERE id = ?', [$gone]);

        $mutations = (new WarehousesTable())->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::productWarehouseStocks,
            '1',
            [
                ObjectProductWarehouseStock::id => 1,
                ObjectProductWarehouseStock::productId => 1,
                ObjectProductWarehouseStock::warehouseId => $gone,
                ObjectProductWarehouseStock::units => 1,
            ],
        ));

        self::assertSame([], $mutations);
    }

    /** Stock written past the ORM, by a row of its own, is counted in the totals as it stands in the database. */
    public function testTheTotalsCountStockWrittenPastTheOrm(): void
    {
        $tag = RandomHelper::hex(8);
        $kyiv = self::warehouse("{$tag} Kyiv");
        $bulbs = self::product(ProductKind::STOCK);
        $seeds = self::product(ProductKind::STOCK);
        Database::sql(
            'INSERT INTO product_warehouse_stock (product_id, warehouse_id, units) VALUES (?, ?, ?), (?, ?, ?)',
            [$bulbs, $kyiv, 9, $seeds, $kyiv, 2],
        );
        self::assertFalse(Hilos::$db->productWarehouseStocks->getObjectCollection()?->isAllLoaded());

        $table = new WarehousesTable();
        $window = $table->getPage(new TableQueryDTO(search: $tag, sort: $table->defaultSort()));

        self::assertFalse(Hilos::$db->productWarehouseStocks->getObjectCollection()?->isAllLoaded());
        self::assertSame([self::row($kyiv, "{$tag} Kyiv", 2, 11)], array_map(
            static fn (AbstractTableRow $row): array => $row->toArray(),
            $window->rows,
        ));
    }

    /**
     * @param int $id Warehouse id
     * @param string $name Warehouse name
     * @param int $productCount Products the warehouse keeps
     * @param int $unitCount Units of them
     * @return array<string, int|string> The row payload the table should carry for the warehouse
     */
    private static function row(int $id, string $name, int $productCount, int $unitCount): array
    {
        return [
            WarehouseTableRow::id => $id,
            WarehouseTableRow::name => $name,
            WarehouseTableRow::address => self::WAREHOUSE_ADDRESS,
            WarehouseTableRow::productCount => $productCount,
            WarehouseTableRow::unitCount => $unitCount,
        ];
    }

    /**
     * @param list<AbstractTableRow> $rows Rows of a window
     * @param list<int> $ids Warehouses of the case
     * @return list<array<string, mixed>> Payloads of the case's warehouses, in the window's order
     */
    private static function rowsOf(array $rows, array $ids): array
    {
        $payloads = [];
        foreach ($rows as $row) {
            if (in_array($row->getRowKey(), $ids, true)) {
                $payloads[] = $row->toArray();
            }
        }

        return $payloads;
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
     * @return array<string, mixed> The stock row of the product in the warehouse, read past the ORM as it is stored
     */
    private static function storedStock(int $productId, int $warehouseId): array
    {
        $rows = Database::sql(
            'SELECT * FROM product_warehouse_stock WHERE product_id = ? AND warehouse_id = ?',
            [$productId, $warehouseId],
        )->rows();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * @param int $productId Product id
     * @param int $warehouseId Warehouse id
     * @return array<string, mixed> The stock row as the ORM announces it, in the keys of its Object
     */
    private static function announcedStock(int $productId, int $warehouseId): array
    {
        return Hilos::$db->productWarehouseStocks[(int)self::storedStock($productId, $warehouseId)[EntityProductWarehouseStock::id]]->toArray();
    }

    /**
     * @param int $warehouseId Warehouse id
     * @return string Name the warehouse is stored under, read past the ORM
     */
    private static function storedName(int $warehouseId): string
    {
        return (string)Database::sql('SELECT name FROM warehouse WHERE id = ?', [$warehouseId])->field('name');
    }

    /**
     * @return int Number of stored warehouses, read past the ORM
     */
    private static function storedWarehouseCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM warehouse')->field('stored');
    }
}

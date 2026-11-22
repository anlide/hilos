<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\DTO\ProductStockTotals;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Entity\Item\Warehouse as EntityWarehouse;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Tables\Product\ProductsTable;
use Demo\EcommerceShop\Tables\Product\ProductTableRow;
use Hilos\AdminViewMode\ViewerFields;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Files\HilosFiles;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The products table over the real shop schema: its window, order, search, filters and their counts, the
 * availability of each kind, and the rows a fact of a product, of its stock, of a warehouse or of a category
 * redraws; the rollback scope proves reads of written rows, not commit.
 *
 * Categories, warehouses and pictures are rows written straight into their tables. Products are rows
 * written the same way, each with the moment of its last edit given, and then the products library
 * declares its set again; a case that edits or creates a product does it through that library under
 * its frame, and the stock is written through its own. Every case reads in the products page's process: the
 * products are held, the stock is not.
 * A stock fact comes in both shapes the ORM announces it in: a sync from another process names the
 * stored columns, a write of this process and a deletion name the fields of the row's Object.
 */
#[IntegrationTest]
final class ProductsTableTest extends ProductsTableEnvironment
{
    /** The most recently edited product is first, and a row carries its category, kind, price, units, warehouse and picture. */
    public function testTheWindowPutsTheRecentlyEditedFirstWithEverythingARowShows(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category("{$tag} Roses");
        $kyiv = self::warehouse("{$tag} Kyiv");
        $bulbs = self::productIn($roses, ProductKind::STOCK, "{$tag} Tulip bulbs", 12_000, 1_000);
        $vase = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Blue vase", 450_000, 3_000);
        $seeds = self::productIn($roses, ProductKind::STOCK, "{$tag} Seeds", 3_000, 2_000);
        self::holdCatalog();
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 12);
        Hilos::$db->productWarehouseStocks->actions->place($vase, $kyiv);

        $table = new ProductsTable();
        $window = self::payloads($table->getPage(new TableQueryDTO(search: $tag, sort: $table->defaultSort()))->rows);

        self::assertSame([
            self::row($vase, "{$tag} Roses", 1, "{$tag} Kyiv", ProductAvailability::FREE),
            self::row($seeds, "{$tag} Roses", 0, null, ProductAvailability::OUT_OF_STOCK),
            self::row($bulbs, "{$tag} Roses", 12, null, ProductAvailability::IN_STOCK),
        ], $window);
        self::assertSame(
            [
                ["{$tag} Blue vase", ProductKind::UNIQUE->value, 450_000, 3_000],
                ["{$tag} Seeds", ProductKind::STOCK->value, 3_000, 2_000],
                ["{$tag} Tulip bulbs", ProductKind::STOCK->value, 12_000, 1_000],
            ],
            array_map(static fn (array $row): array => [
                $row[ProductTableRow::name],
                $row[ProductTableRow::kind],
                $row[ProductTableRow::price],
                $row[ProductTableRow::changedAt],
            ], $window),
        );
        self::assertSame(
            HilosFiles::downloadPath(self::storedPicture($vase), CatalogPictureUploadTarget::VARIANT_XS),
            $window[0][ProductTableRow::pictureUrl],
        );
    }

    /** The name, the category's name and the price each order the window both ways. */
    public function testTheNameTheCategoryAndThePriceOrderTheWindowBothWays(): void
    {
        $tag = RandomHelper::hex(8);
        $amber = self::productIn(self::category("{$tag} Roses"), ProductKind::STOCK, "{$tag} Amber", 30_000, 1_000);
        $birch = self::productIn(self::category("{$tag} Tulips"), ProductKind::STOCK, "{$tag} Birch", 10_000, 2_000);
        $cedar = self::productIn(self::category("{$tag} Lilies"), ProductKind::STOCK, "{$tag} Cedar", 20_000, 3_000);
        self::holdCatalog();
        $table = new ProductsTable();

        $orders = [
            ProductTableRow::name => [$amber, $birch, $cedar],
            ProductTableRow::categoryName => [$cedar, $amber, $birch],
            ProductTableRow::price => [$birch, $cedar, $amber],
        ];
        foreach ($orders as $field => $ascending) {
            self::assertSame($ascending, self::keysOf($table, $tag, $field, TableConstants::ORDER_ASC), $field);
            self::assertSame(array_reverse($ascending), self::keysOf($table, $tag, $field, TableConstants::ORDER_DESC), $field);
        }
    }

    /** A search keeps the products whose name holds the term, at its start or in its middle. */
    public function testASearchKeepsTheProductsWhoseNameHoldsTheTerm(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category();
        $bulbs = self::productIn($roses, ProductKind::STOCK, "Tulip {$tag} bulbs", 12_000, 3_000);
        $seeds = self::productIn($roses, ProductKind::STOCK, "Tulip {$tag} seeds", 3_000, 2_000);
        $bush = self::productIn($roses, ProductKind::UNIQUE, "Rose {$tag} bush", 45_000, 1_000);
        self::holdCatalog();
        $table = new ProductsTable();
        $keysFor = static fn (string $term): array => self::keys($table, new TableQueryDTO(search: $term, sort: $table->defaultSort()));

        self::assertSame([$bulbs, $seeds, $bush], $keysFor($tag));
        self::assertSame([$bulbs, $seeds], $keysFor("Tulip {$tag}"));
        self::assertSame([$seeds], $keysFor("{$tag} s"));
        self::assertSame([], $keysFor("{$tag} peony"));
    }

    /** Category, kind and availability narrow the window alone and together; an unknown or malformed value empties it. */
    public function testTheFiltersNarrowAloneAndTogetherAndAWrongValueEmptiesTheWindow(): void
    {
        $tag = RandomHelper::hex(8);
        [$roses, $tulips, $rosesStock, $rosesVase, $tulipsStock, $tulipsVase] = self::fourProducts($tag);
        $table = new ProductsTable();
        $keysFor = static fn (array $filter): array => self::keys(
            $table,
            new TableQueryDTO(search: $tag, sort: $table->defaultSort(), filter: $filter),
        );

        self::assertSame([$rosesStock, $rosesVase, $tulipsStock, $tulipsVase], $keysFor([]));
        self::assertSame([$rosesStock, $rosesVase], $keysFor([ProductsTable::FILTER_CATEGORY => $roses]));
        self::assertSame([$tulipsStock, $tulipsVase], $keysFor([ProductsTable::FILTER_CATEGORY => (string)$tulips]));
        self::assertSame([$rosesStock, $tulipsStock], $keysFor([ProductsTable::FILTER_KIND => ProductKind::STOCK->value]));
        self::assertSame([$rosesVase], $keysFor([ProductsTable::FILTER_AVAILABILITY => ProductAvailability::FREE->value]));
        self::assertSame([$tulipsVase], $keysFor([ProductsTable::FILTER_AVAILABILITY => ProductAvailability::NOT_PLACED->value]));
        self::assertSame([$rosesVase], $keysFor([
            ProductsTable::FILTER_CATEGORY => $roses,
            ProductsTable::FILTER_KIND => ProductKind::UNIQUE->value,
        ]));
        self::assertSame([$tulipsStock], $keysFor([
            ProductsTable::FILTER_CATEGORY => $tulips,
            ProductsTable::FILTER_KIND => ProductKind::STOCK->value,
            ProductsTable::FILTER_AVAILABILITY => ProductAvailability::OUT_OF_STOCK->value,
        ]));
        self::assertSame([], $keysFor([
            ProductsTable::FILTER_CATEGORY => $roses,
            ProductsTable::FILTER_AVAILABILITY => ProductAvailability::NOT_PLACED->value,
        ]));
        self::assertSame([$rosesStock, $rosesVase, $tulipsStock, $tulipsVase], $keysFor([ProductsTable::FILTER_KIND => '']));

        foreach (
            [
                [ProductsTable::FILTER_CATEGORY => $tulips + 1_000],
                [ProductsTable::FILTER_KIND => 'bouquet'],
                [ProductsTable::FILTER_AVAILABILITY => 'sold_out'],
                [ProductsTable::FILTER_CATEGORY => 'roses'],
                [ProductsTable::FILTER_CATEGORY => -1],
                [ProductsTable::FILTER_KIND => 5],
            ] as $filter
        ) {
            self::assertSame([], $keysFor($filter), var_export($filter, true));
        }
    }

    /** An option is counted over the set without its own filter, "any" over the set with that filter lifted, every count exact. */
    public function testTheFacetsCountEachOptionWithoutItsOwnFilter(): void
    {
        $tag = RandomHelper::hex(8);
        [$roses, $tulips] = self::fourProducts($tag);
        $table = new ProductsTable();

        $facets = $table->facetCounts(
            $table->scopeSearch(new TableQueryDTO(search: $tag, filter: [ProductsTable::FILTER_KIND => ProductKind::STOCK->value])),
            [
                ProductsTable::FILTER_CATEGORY => [$roses, $tulips],
                ProductsTable::FILTER_KIND => [ProductKind::STOCK->value, ProductKind::UNIQUE->value],
                ProductsTable::FILTER_AVAILABILITY => [
                    ProductAvailability::LOW->value,
                    ProductAvailability::OUT_OF_STOCK->value,
                    ProductAvailability::FREE->value,
                    ProductAvailability::NOT_PLACED->value,
                ],
                ProductTableRow::name => ["{$tag} Rose bulbs"],
            ],
        );

        self::assertEquals([
            ProductsTable::FILTER_CATEGORY => self::facet(2, [$roses => 1, $tulips => 1]),
            ProductsTable::FILTER_KIND => self::facet(4, [ProductKind::STOCK->value => 2, ProductKind::UNIQUE->value => 2]),
            ProductsTable::FILTER_AVAILABILITY => self::facet(2, [
                ProductAvailability::LOW->value => 1,
                ProductAvailability::OUT_OF_STOCK->value => 1,
                ProductAvailability::FREE->value => 0,
                ProductAvailability::NOT_PLACED->value => 0,
            ]),
        ], $facets);
    }

    /** A stock item is out of stock, low or in stock by its units in all warehouses; a unique piece is free only in a warehouse. */
    public function testEachKindShowsItsAvailabilityByItsUnits(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category("{$tag} Roses");
        $kyiv = self::warehouse("{$tag} Kyiv");
        $lviv = self::warehouse("{$tag} Lviv");
        $none = self::productIn($roses, ProductKind::STOCK, "{$tag} None", 1_000, 1_000);
        $one = self::productIn($roses, ProductKind::STOCK, "{$tag} One", 1_000, 1_000);
        $five = self::productIn($roses, ProductKind::STOCK, "{$tag} Five", 1_000, 1_000);
        $six = self::productIn($roses, ProductKind::STOCK, "{$tag} Six", 1_000, 1_000);
        $unplaced = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Unplaced", 1_000, 1_000);
        $placed = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Placed", 1_000, 1_000);
        self::holdCatalog();
        Hilos::$db->productWarehouseStocks->actions->setUnits($one, $kyiv, 1);
        Hilos::$db->productWarehouseStocks->actions->setUnits($five, $kyiv, 5);
        Hilos::$db->productWarehouseStocks->actions->setUnits($six, $kyiv, 4);
        Hilos::$db->productWarehouseStocks->actions->setUnits($six, $lviv, 2);
        Hilos::$db->productWarehouseStocks->actions->place($placed, $lviv);

        $table = new ProductsTable();

        self::assertSame(self::row($none, "{$tag} Roses", 0, null, ProductAvailability::OUT_OF_STOCK), $table->findRow($none)?->toArray());
        self::assertSame(self::row($one, "{$tag} Roses", 1, null, ProductAvailability::LOW), $table->findRow($one)?->toArray());
        self::assertSame(self::row($five, "{$tag} Roses", 5, null, ProductAvailability::LOW), $table->findRow($five)?->toArray());
        self::assertSame(self::row($six, "{$tag} Roses", 6, null, ProductAvailability::IN_STOCK), $table->findRow($six)?->toArray());
        self::assertSame(
            self::row($unplaced, "{$tag} Roses", 0, null, ProductAvailability::NOT_PLACED),
            $table->findRow($unplaced)?->toArray(),
        );
        self::assertSame(
            self::row($placed, "{$tag} Roses", 1, "{$tag} Lviv", ProductAvailability::FREE),
            $table->findRow($placed)?->toArray(),
        );
    }

    /** The totals are found by a query on the products, not read whole, and the table shows them as the holder of the stock would. */
    public function testTheTotalsComeFromAQueryWhereTheStockIsNotHeld(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category("{$tag} Roses");
        $kyiv = self::warehouse("{$tag} Kyiv");
        $lviv = self::warehouse("{$tag} Lviv");
        $bulbs = self::productIn($roses, ProductKind::STOCK, "{$tag} Tulip bulbs", 12_000, 1_000);
        $vase = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Blue vase", 450_000, 2_000);
        $seeds = self::productIn($roses, ProductKind::STOCK, "{$tag} Seeds", 3_000, 3_000);
        self::holdCatalog();
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
        self::assertEquals(new ProductStockTotals(7, null, 2), $totals->of($bulbs));
        self::assertEquals(new ProductStockTotals(1, $lviv, 1), $totals->of($vase));
        self::assertEquals(new ProductStockTotals(0, null, 0), $totals->of($seeds));
        self::assertCount(0, Hilos::$db->productWarehouseStocks->totalsOfProducts());

        $table = new ProductsTable();

        self::assertSame(self::row($bulbs, "{$tag} Roses", 7, null, ProductAvailability::IN_STOCK), $table->findRow($bulbs)?->toArray());
        self::assertSame(self::row($vase, "{$tag} Roses", 1, "{$tag} Lviv", ProductAvailability::FREE), $table->findRow($vase)?->toArray());
    }

    /** Units put in, changed and taken away to zero each redraw their product with its units and availability. */
    public function testUnitsSetChangedAndTakenAwayRedrawTheirProduct(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category("{$tag} Roses");
        $kyiv = self::warehouse();
        $bulbs = self::productIn($roses, ProductKind::STOCK, "{$tag} Tulip bulbs", 12_000, 1_000);
        self::productIn($roses, ProductKind::STOCK, "{$tag} Seeds", 3_000, 1_000);
        self::holdCatalog();
        $table = new ProductsTable();

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 4);
        $synced = self::storedStock($bulbs, $kyiv);
        $stockId = (string)$synced[EntityProductWarehouseStock::id];
        $created = $table->buildMutationsForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::productWarehouseStocks,
            $stockId,
            $synced,
        ));
        self::assertSame(
            [[TableMutationType::Update, $bulbs, self::row($bulbs, "{$tag} Roses", 4, null, ProductAvailability::LOW)]],
            self::described($created),
        );

        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 9);
        $changed = $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::productWarehouseStocks,
            $stockId,
            [EntityProductWarehouseStock::units => 9],
            previous: [EntityProductWarehouseStock::units => 4],
        ));
        self::assertSame(
            [[TableMutationType::Update, $bulbs, self::row($bulbs, "{$tag} Roses", 9, null, ProductAvailability::IN_STOCK)]],
            self::described($changed),
        );

        $announced = Hilos::$db->productWarehouseStocks[(int)$stockId]->toArray();
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 0);
        $takenAway = $table->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::productWarehouseStocks,
            $stockId,
            $announced,
        ));
        self::assertSame(
            [[TableMutationType::Update, $bulbs, self::row($bulbs, "{$tag} Roses", 0, null, ProductAvailability::OUT_OF_STOCK)]],
            self::described($takenAway),
        );
    }

    /** A unique piece placed for the first time is free in its warehouse, and moved it shows the new one. */
    public function testAPiecePlacedAndMovedShowsItsWarehouse(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category("{$tag} Roses");
        $kyiv = self::warehouse("{$tag} Kyiv");
        $lviv = self::warehouse("{$tag} Lviv");
        $vase = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Blue vase", 450_000, 1_000);
        self::holdCatalog();
        $table = new ProductsTable();

        Hilos::$db->productWarehouseStocks->actions->place($vase, $kyiv);
        $announced = self::announcedStock($vase, $kyiv);
        $stockId = (string)$announced[ObjectProductWarehouseStock::id];
        $placed = $table->buildMutationsForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::productWarehouseStocks,
            $stockId,
            $announced,
        ));
        self::assertSame(
            [[TableMutationType::Update, $vase, self::row($vase, "{$tag} Roses", 1, "{$tag} Kyiv", ProductAvailability::FREE)]],
            self::described($placed),
        );

        Hilos::$db->productWarehouseStocks->actions->place($vase, $lviv);
        $moved = $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::productWarehouseStocks,
            $stockId,
            [EntityProductWarehouseStock::warehouse_id => $lviv],
            previous: [EntityProductWarehouseStock::warehouse_id => $kyiv],
        ));
        self::assertSame(
            [[TableMutationType::Update, $vase, self::row($vase, "{$tag} Roses", 1, "{$tag} Lviv", ProductAvailability::FREE)]],
            self::described($moved),
        );
    }

    /** A stock fact naming a product that is gone redraws nothing, in either shape. */
    public function testAStockFactOfAVanishedProductRedrawsNothing(): void
    {
        $kyiv = self::warehouse();
        $gone = self::productIn(self::category(), ProductKind::STOCK, 'Gone ' . RandomHelper::hex(8), 1_000, 1_000);
        Database::sql('DELETE FROM product WHERE id = ?', [$gone]);
        self::holdCatalog();
        $table = new ProductsTable();

        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::productWarehouseStocks,
            '1',
            [
                EntityProductWarehouseStock::id => 1,
                EntityProductWarehouseStock::product_id => $gone,
                EntityProductWarehouseStock::warehouse_id => $kyiv,
                EntityProductWarehouseStock::units => 3,
            ],
        )));
        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::productWarehouseStocks,
            '1',
            [
                ObjectProductWarehouseStock::id => 1,
                ObjectProductWarehouseStock::productId => $gone,
                ObjectProductWarehouseStock::warehouseId => $kyiv,
                ObjectProductWarehouseStock::units => 3,
            ],
        )));
    }

    /** A new product enters the window, an edited one is redrawn, a deleted one leaves by its id, and a vanished one is ignored. */
    public function testAProductCreatedEditedAndDeletedMovesItsRow(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category("{$tag} Roses");
        $gone = self::productIn($roses, ProductKind::STOCK, "{$tag} Gone", 1_000, 1_000);
        Database::sql('DELETE FROM product WHERE id = ?', [$gone]);
        $doomed = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Doomed", 1_000, 1_000);
        $picture = self::pictureFile();
        self::holdCatalog();
        $table = new ProductsTable();

        $peony = $this->asProductsLibrary(
            static fn (): int => Hilos::$db->products->actions->create("{$tag} Peony", $roses, ProductKind::STOCK, 9_000, 'Pink peony', $picture)->id,
        );
        $created = $table->buildMutationsForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::products,
            (string)$peony,
            self::storedProduct($peony),
        ));
        self::assertSame(
            [[TableMutationType::Create, $peony, self::row($peony, "{$tag} Roses", 0, null, ProductAvailability::OUT_OF_STOCK)]],
            self::described($created),
        );

        $before = self::storedProduct($peony);
        $this->asProductsLibrary(
            static fn () => Hilos::$db->products[$peony]->actions->update("{$tag} Peony", $roses, ProductKind::STOCK, 11_000, 'Pink peony', null),
        );
        $after = self::storedProduct($peony);
        $edited = $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::products,
            (string)$peony,
            [EntityProduct::price => $after[EntityProduct::price], EntityProduct::changed_at => $after[EntityProduct::changed_at]],
            previous: [EntityProduct::price => $before[EntityProduct::price], EntityProduct::changed_at => $before[EntityProduct::changed_at]],
        ));
        self::assertSame(
            [[TableMutationType::Update, $peony, self::row($peony, "{$tag} Roses", 0, null, ProductAvailability::OUT_OF_STOCK)]],
            self::described($edited),
        );
        self::assertSame(11_000, $edited[0]->row?->toArray()[ProductTableRow::price]);

        $announced = self::storedProduct($doomed);
        Database::sql('DELETE FROM product WHERE id = ?', [$doomed]);
        $deleted = $table->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::products,
            (string)$doomed,
            $announced,
        ));
        self::assertSame([[TableMutationType::Delete, $doomed, null]], self::described($deleted));

        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::products,
            (string)$gone,
            [EntityProduct::price => 2_000],
            previous: [EntityProduct::price => 1_000],
        )));
    }

    /** A renamed warehouse redraws the unique pieces it keeps and no stock item; a created or deleted one redraws nothing. */
    public function testARenamedWarehouseRedrawsOnlyTheUniquePiecesItKeeps(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category("{$tag} Roses");
        $podil = self::warehouse("{$tag} Podil");
        $lviv = self::warehouse("{$tag} Lviv");
        $empty = self::warehouse();
        $vase = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Blue vase", 450_000, 1_000);
        $jug = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Jug", 90_000, 1_000);
        $bulbs = self::productIn($roses, ProductKind::STOCK, "{$tag} Tulip bulbs", 12_000, 1_000);
        Database::sql(
            'INSERT INTO product_warehouse_stock (product_id, warehouse_id, units) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)',
            [$vase, $podil, 1, $jug, $lviv, 1, $bulbs, $podil, 8],
        );
        self::holdCatalog();
        $table = new ProductsTable();

        Database::sql('UPDATE warehouse SET name = ? WHERE id = ?', ["{$tag} Rynok", $podil]);
        $renamed = $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::warehouses,
            (string)$podil,
            [EntityWarehouse::name => "{$tag} Rynok"],
            previous: [EntityWarehouse::name => "{$tag} Podil"],
        ));
        self::assertSame(
            [[TableMutationType::Update, $vase, self::row($vase, "{$tag} Roses", 1, "{$tag} Rynok", ProductAvailability::FREE)]],
            self::described($renamed),
        );

        $created = [
            EntityWarehouse::id => $empty,
            EntityWarehouse::name => self::storedWarehouseName($empty),
            EntityWarehouse::address => self::WAREHOUSE_ADDRESS,
        ];
        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::warehouses,
            (string)$empty,
            $created,
        )));
        Database::sql('DELETE FROM warehouse WHERE id = ?', [$empty]);
        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::warehouses,
            (string)$empty,
            $created,
        )));
    }

    /** A renamed category redraws each of its products and no other; a created or deleted one redraws nothing. */
    public function testARenamedCategoryRedrawsItsProducts(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category("{$tag} Roses");
        $tulips = self::category("{$tag} Tulips");
        $empty = self::category();
        $bush = self::productIn($roses, ProductKind::STOCK, "{$tag} Rose bush", 45_000, 1_000);
        $vase = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Rose vase", 450_000, 1_000);
        self::productIn($tulips, ProductKind::STOCK, "{$tag} Tulip bulbs", 12_000, 1_000);
        self::holdCatalog();
        $table = new ProductsTable();

        Database::sql('UPDATE category SET name = ? WHERE id = ?', ["{$tag} Red roses", $roses]);
        $renamed = self::described($table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::categories,
            (string)$roses,
            [EntityCategory::name => "{$tag} Red roses"],
            previous: [EntityCategory::name => "{$tag} Roses"],
        )));
        usort($renamed, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        self::assertSame([
            [TableMutationType::Update, $bush, self::row($bush, "{$tag} Red roses", 0, null, ProductAvailability::OUT_OF_STOCK)],
            [TableMutationType::Update, $vase, self::row($vase, "{$tag} Red roses", 0, null, ProductAvailability::NOT_PLACED)],
        ], $renamed);

        $created = [
            EntityCategory::id => $empty,
            EntityCategory::name => (string)Database::sql('SELECT name FROM category WHERE id = ?', [$empty])->field(EntityCategory::name),
        ];
        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::dbCreated(
            EcommerceShopDbContext::categories,
            (string)$empty,
            $created,
        )));
        Database::sql('DELETE FROM category WHERE id = ?', [$empty]);
        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            EcommerceShopDbContext::categories,
            (string)$empty,
            $created,
        )));
    }

    /** The set answers for one product under the window's filters and search the way the window would. */
    public function testTheSetAnswersForOneProductUnderFiltersAndSearch(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category();
        $kyiv = self::warehouse();
        $bulbs = self::productIn($roses, ProductKind::STOCK, "{$tag} Tulip bulbs", 12_000, 1_000);
        $bush = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Rose bush", 45_000, 1_000);
        self::holdCatalog();
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 3);
        $table = new ProductsTable();

        $stockTulips = new TableQueryDTO(search: "{$tag} Tulip", filter: [ProductsTable::FILTER_KIND => ProductKind::STOCK->value]);
        self::assertTrue($table->containsRow($bulbs, $stockTulips));
        self::assertTrue($table->containsRow((string)$bulbs, $stockTulips));
        self::assertFalse($table->containsRow($bush, $stockTulips));

        $low = new TableQueryDTO(search: $tag, filter: [ProductsTable::FILTER_AVAILABILITY => ProductAvailability::LOW->value]);
        self::assertTrue($table->containsRow($bulbs, $low));
        self::assertFalse($table->containsRow($bush, $low));

        $uniqueTulips = new TableQueryDTO(search: "{$tag} Tulip", filter: [ProductsTable::FILTER_KIND => ProductKind::UNIQUE->value]);
        self::assertFalse($table->containsRow($bulbs, $uniqueTulips));

        $roseSearch = new TableQueryDTO(search: "{$tag} Rose");
        self::assertTrue($table->containsRow($bush, $roseSearch));
        self::assertFalse($table->containsRow($bulbs, $roseSearch));
        self::assertFalse($table->containsRow('bulbs', $roseSearch));
    }

    /** Every field of a row comes from a column none of the shop's catalog entities holds personal, so a viewer sees the whole row. */
    public function testAViewerOfTheAdminViewModeSeesTheWholeRow(): void
    {
        $tag = RandomHelper::hex(8);
        $roses = self::category("{$tag} Roses");
        $kyiv = self::warehouse("{$tag} Kyiv");
        $bulbs = self::productIn($roses, ProductKind::STOCK, "{$tag} Tulip bulbs", 12_000, 1_000);
        $vase = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Blue vase", 450_000, 2_000);
        self::holdCatalog();
        Hilos::$db->productWarehouseStocks->actions->setUnits($bulbs, $kyiv, 7);
        Hilos::$db->productWarehouseStocks->actions->place($vase, $kyiv);
        $notPersonal = [
            EcommerceShopDbContext::products => EntityProduct::_piiNotPersonal,
            EcommerceShopDbContext::categories => EntityCategory::_piiNotPersonal,
            EcommerceShopDbContext::warehouses => EntityWarehouse::_piiNotPersonal,
        ];
        $columnShown = static fn (string $collection, string $field): bool => in_array(
            Hilos::$db->{$collection}->columnForField($field),
            $notPersonal[$collection] ?? [],
            true,
        );

        $table = new ProductsTable();
        $rows = self::payloads($table->getPage(new TableQueryDTO(search: $tag, sort: $table->defaultSort()))->rows);

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($row, ViewerFields::hide($row, $table->wireFields(), $columnShown));
        }
    }

    /**
     * Writes two categories and four products, one of each kind in each, and gives them the stock their names say.
     *
     * The roses' stock item has three units and their unique piece is placed; the tulips' stock item
     * has none and their unique piece is not placed. They were edited in the order the list names
     * them, the first last.
     *
     * @param string $tag Prefix of the products' names
     * @return list<int> The roses, the tulips, the roses' stock item and piece, the tulips' stock item and piece
     */
    private static function fourProducts(string $tag): array
    {
        $roses = self::category("{$tag} Roses");
        $tulips = self::category("{$tag} Tulips");
        $kyiv = self::warehouse();
        $rosesStock = self::productIn($roses, ProductKind::STOCK, "{$tag} Rose bulbs", 12_000, 4_000);
        $rosesVase = self::productIn($roses, ProductKind::UNIQUE, "{$tag} Rose vase", 450_000, 3_000);
        $tulipsStock = self::productIn($tulips, ProductKind::STOCK, "{$tag} Tulip bulbs", 9_000, 2_000);
        $tulipsVase = self::productIn($tulips, ProductKind::UNIQUE, "{$tag} Tulip vase", 300_000, 1_000);
        self::holdCatalog();
        Hilos::$db->productWarehouseStocks->actions->setUnits($rosesStock, $kyiv, 3);
        Hilos::$db->productWarehouseStocks->actions->place($rosesVase, $kyiv);

        return [$roses, $tulips, $rosesStock, $rosesVase, $tulipsStock, $tulipsVase];
    }

    /**
     * @param int $id Product id
     * @param string $categoryName Name of the product's category
     * @param int $units Units the row should show
     * @param ?string $warehouseName Warehouse the row should show
     * @param ProductAvailability $availability Availability the row should show
     * @return array<string, int|string|null> The row payload the table should carry for the product, its columns as stored
     */
    private static function row(int $id, string $categoryName, int $units, ?string $warehouseName, ProductAvailability $availability): array
    {
        $stored = self::storedProduct($id);

        return [
            ProductTableRow::id => $id,
            ProductTableRow::name => $stored[EntityProduct::name],
            ProductTableRow::pictureUrl => HilosFiles::downloadPath($stored[EntityProduct::picture_file_id], CatalogPictureUploadTarget::VARIANT_XS),
            ProductTableRow::categoryId => $stored[EntityProduct::category_id],
            ProductTableRow::categoryName => $categoryName,
            ProductTableRow::kind => $stored[EntityProduct::kind],
            ProductTableRow::price => $stored[EntityProduct::price],
            ProductTableRow::units => $units,
            ProductTableRow::warehouseName => $warehouseName,
            ProductTableRow::availability => $availability->value,
            ProductTableRow::changedAt => $stored[EntityProduct::changed_at],
        ];
    }

    /**
     * @param int $count Products the set without the filter holds
     * @param array<array-key, int> $options Products each option leaves
     * @return array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>} The exact counts of one filter
     */
    private static function facet(int $count, array $options): array
    {
        return [
            TableConstants::FACET_KEY_ANY => new TableFacetCountDTO($count, true),
            TableConstants::FACET_KEY_OPTIONS => array_map(
                static fn (int $option): TableFacetCountDTO => new TableFacetCountDTO($option, true),
                $options,
            ),
        ];
    }

    /**
     * @param ProductsTable $table Table to read
     * @param string $tag Prefix of the case's products
     * @param string $field Field to order by
     * @param string $order Direction of the order
     * @return list<int|string> Ids of the case's products in that order
     */
    private static function keysOf(ProductsTable $table, string $tag, string $field, string $order): array
    {
        return self::keys($table, new TableQueryDTO(search: $tag, sort: TableSortOrderDTO::of(new TableSortDTO($field, $order))));
    }

    /**
     * @param ProductsTable $table Table to read
     * @param TableQueryDTO $query Window query
     * @return list<int|string> Row keys of the window, in its order
     */
    private static function keys(ProductsTable $table, TableQueryDTO $query): array
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
     * @return array<string, int|string> The stored product row, read past the ORM, in its stored columns,
     *     as a sync from another process announces it
     */
    private static function storedProduct(int $productId): array
    {
        $stored = Database::sql('SELECT * FROM product WHERE id = ?', [$productId])->rows()[0];

        return [
            EntityProduct::id => (int)$stored[EntityProduct::id],
            EntityProduct::name => (string)$stored[EntityProduct::name],
            EntityProduct::category_id => (int)$stored[EntityProduct::category_id],
            EntityProduct::kind => (string)$stored[EntityProduct::kind],
            EntityProduct::price => (int)$stored[EntityProduct::price],
            EntityProduct::description => (string)$stored[EntityProduct::description],
            EntityProduct::picture_file_id => (int)$stored[EntityProduct::picture_file_id],
            EntityProduct::slug => (string)$stored[EntityProduct::slug],
            EntityProduct::changed_at => (int)$stored[EntityProduct::changed_at],
        ];
    }

    /**
     * @param int $productId Product id
     * @return int Registry file the product shows, read past the ORM
     */
    private static function storedPicture(int $productId): int
    {
        return (int)self::storedProduct($productId)[EntityProduct::picture_file_id];
    }

    /**
     * @param int $productId Product id
     * @param int $warehouseId Warehouse id
     * @return array<string, mixed> The stock row of the product in the warehouse, read past the ORM in its stored columns,
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
     * @param int $productId Product id
     * @param int $warehouseId Warehouse id
     * @return array<string, mixed> The stock row as this process announces it, in the fields of its Object
     */
    private static function announcedStock(int $productId, int $warehouseId): array
    {
        return Hilos::$db->productWarehouseStocks[(int)self::storedStock($productId, $warehouseId)[EntityProductWarehouseStock::id]]->toArray();
    }

    /**
     * @param int $warehouseId Warehouse id
     * @return string Name the warehouse is stored under, read past the ORM
     */
    private static function storedWarehouseName(int $warehouseId): string
    {
        return (string)Database::sql('SELECT name FROM warehouse WHERE id = ?', [$warehouseId])->field(EntityWarehouse::name);
    }
}

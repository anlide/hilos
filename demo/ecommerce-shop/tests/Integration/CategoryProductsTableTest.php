<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Entity\Item\Warehouse as EntityWarehouse;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Tables\Product\CategoryProductsTable;
use Demo\EcommerceShop\Tables\Product\CategoryProductTableRow;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DbSyncApplicator;
use Hilos\Files\HilosFiles;
use Hilos\Testing\IntegrationTest;

/** Exercises the storefront's category windows and source facts with a lazy catalog and lazy stock. */
#[IntegrationTest]
final class CategoryProductsTableTest extends CategoryProductsTableEnvironment
{
    /** The first window shows every visible product of its category in ascending price and id order. */
    public function testTheWindowReadsOnlyItsCategoryWithoutHoldingTheCatalog(): void
    {
        $category = self::category();
        $kyiv = self::warehouse();
        $lviv = self::warehouse();
        $inStock = self::productIn($category, ProductKind::STOCK, 'Tulip bulbs', 3_000, 1_000, 'Pack of red tulip bulbs');
        $striped = self::productIn($category, ProductKind::UNIQUE, 'Blue vase', 1_000, 1_000, 'Blue glaze with white stripes');
        $speckled = self::productIn($category, ProductKind::UNIQUE, 'Blue vase', 1_000, 1_000, 'Blue glaze with black speckles');
        $low = self::productIn($category, ProductKind::STOCK, 'Rose seeds', 3_000, 1_000);
        $out = self::productIn($category, ProductKind::STOCK, 'Dahlia bulbs', 2_000, 1_000);
        self::productIn($category, ProductKind::UNIQUE, 'Unplaced jug', 500, 1_000);
        self::productIn(self::category(), ProductKind::STOCK, 'Other bulbs', 700, 1_000);
        Hilos::$db->productWarehouseStocks->actions->setUnits($inStock, $kyiv, 4);
        Hilos::$db->productWarehouseStocks->actions->setUnits($inStock, $lviv, 3);
        Hilos::$db->productWarehouseStocks->actions->setUnits($low, $kyiv, 5);
        Hilos::$db->productWarehouseStocks->actions->place($striped, $lviv);
        Hilos::$db->productWarehouseStocks->actions->place($speckled, $lviv);

        $table = new CategoryProductsTable();
        $products = Hilos::$db->products->getObjectCollection();
        $stock = Hilos::$db->productWarehouseStocks->getObjectCollection();
        self::assertNotNull($products);
        self::assertNotNull($stock);
        self::assertFalse($products->isAllLoaded());
        self::assertFalse($stock->isAllLoaded());

        $window = $table->getPage(new TableQueryDTO(
            sort: $table->defaultSort(),
            filter: [CategoryProductsTable::FILTER_CATEGORY => $category],
        ));

        self::assertFalse($products->isAllLoaded());
        self::assertFalse($stock->isAllLoaded());
        self::assertSame(5, $window->totalCount);
        self::assertSame([
            ['rowKey' => $striped, 'sources' => ['product' => self::row($striped, 1, 'free', 'Blue glaze with white stripes')]],
            ['rowKey' => $speckled, 'sources' => ['product' => self::row($speckled, 1, 'free', 'Blue glaze with black speckles')]],
            ['rowKey' => $out, 'sources' => ['product' => self::row($out, 0, 'out_of_stock')]],
            ['rowKey' => $inStock, 'sources' => ['product' => self::row($inStock, 7, 'in_stock')]],
            ['rowKey' => $low, 'sources' => ['product' => self::row($low, 5, 'low')]],
        ], array_map($table->browserRow(...), $window->rows));
    }

    /** A description-only product fact refreshes the alternative text of a placed unique piece. */
    public function testDescriptionChangesUpdateThePictureAlt(): void
    {
        $category = self::category();
        $warehouse = self::warehouse();
        $before = 'Blue glaze with white stripes';
        $after = 'Blue glaze with raised white stripes';
        $product = self::productIn($category, ProductKind::UNIQUE, 'Blue vase', 1_000, 1_000, $before);
        Hilos::$db->productWarehouseStocks->actions->place($product, $warehouse);
        $table = new CategoryProductsTable();
        $row = self::row($product, 1, 'free', $before);
        self::assertSame($row, $table->findRow($product)?->toArray());

        Database::sql('UPDATE product SET description = ? WHERE id = ?', [$after, $product]);
        $fields = [EntityProduct::description => $after];
        $previous = [EntityProduct::description => $before];
        DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::products,
            (string) $product,
            $fields,
            previous: $previous,
        ));
        $changed = SourceChange::dbUpdated(EcommerceShopDbContext::products, (string) $product, $fields, previous: $previous);

        $row['pictureAlt'] = $after;
        self::assertSame([[TableMutationType::Update, $product, $row]], self::described($table->buildMutationsForSourceEvent($changed)));
    }

    /** A missing category and malformed category ids give an empty window and a zero total. */
    public function testTheWindowRequiresAPositiveCategoryId(): void
    {
        $category = self::category();
        $product = self::productIn($category, ProductKind::STOCK, 'Tulip bulbs', 3_000, 1_000);
        $table = new CategoryProductsTable();

        self::assertSame([$product], self::keys($table->getPage(new TableQueryDTO(
            filter: [CategoryProductsTable::FILTER_CATEGORY => (string) $category],
        ))));

        $filters = [[]];
        foreach (['abc', 0, -1, '0', PHP_INT_MAX . '0'] as $invalid) {
            $filters[] = [CategoryProductsTable::FILTER_CATEGORY => $invalid];
        }
        foreach ($filters as $filter) {
            $window = $table->getPage(new TableQueryDTO(filter: $filter));
            self::assertSame([], $window->rows);
            self::assertSame(0, $window->totalCount);
        }
    }

    /** A two-row window in descending price order leaves the cheapest item on the second page. */
    public function testTheWindowCanPageByDescendingPrice(): void
    {
        $category = self::category();
        $middle = self::productIn($category, ProductKind::STOCK, 'Tulip bulbs', 2_000, 1_000);
        $cheap = self::productIn($category, ProductKind::STOCK, 'Rose seeds', 1_000, 1_000);
        $expensive = self::productIn($category, ProductKind::STOCK, 'Dahlia bulbs', 3_000, 1_000);
        $table = new CategoryProductsTable();
        $sort = TableSortOrderDTO::of(new TableSortDTO(CategoryProductTableRow::price, TableConstants::ORDER_DESC));
        $filter = [CategoryProductsTable::FILTER_CATEGORY => $category];

        $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, filter: $filter));
        $second = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, filter: $filter, pageIndex: 1));

        self::assertSame([$expensive, $middle], self::keys($first));
        self::assertSame([$cheap], self::keys($second));
        self::assertSame(3, $first->totalCount);
        self::assertSame(3, $second->totalCount);
    }

    /** The window searches the name and narrows by kind and by the two storefront availability choices. */
    public function testTheWindowCanSearchAndFilter(): void
    {
        $category = self::category();
        $warehouse = self::warehouse();
        $free = self::productIn($category, ProductKind::UNIQUE, 'Blue vase', 1_000, 1_000);
        $inStock = self::productIn($category, ProductKind::STOCK, 'Tulip bulbs', 2_000, 1_000);
        $low = self::productIn($category, ProductKind::STOCK, 'Rose seeds', 3_000, 1_000);
        $out = self::productIn($category, ProductKind::STOCK, 'Dahlia bulbs', 4_000, 1_000);
        Hilos::$db->productWarehouseStocks->actions->place($free, $warehouse);
        Hilos::$db->productWarehouseStocks->actions->setUnits($inStock, $warehouse, 10);
        Hilos::$db->productWarehouseStocks->actions->setUnits($low, $warehouse, 3);

        $table = new CategoryProductsTable();
        $categoryFilter = [CategoryProductsTable::FILTER_CATEGORY => $category];
        $sort = $table->defaultSort();
        self::assertSame([$inStock, $out], self::keys($table->getPage(new TableQueryDTO(search: 'bulb', sort: $sort, filter: $categoryFilter))));
        self::assertSame([], self::keys($table->getPage(new TableQueryDTO(search: 'Product of the case', filter: $categoryFilter))));

        foreach ([
            [CategoryProductsTable::FILTER_KIND, ProductKind::UNIQUE->value, [$free]],
            [CategoryProductsTable::FILTER_KIND, ProductKind::STOCK->value, [$inStock, $low, $out]],
            [CategoryProductsTable::FILTER_AVAILABILITY, CategoryProductsTable::AVAILABILITY_AVAILABLE, [$free, $inStock, $low]],
            [CategoryProductsTable::FILTER_AVAILABILITY, CategoryProductsTable::AVAILABILITY_OUT_OF_STOCK, [$out]],
            [CategoryProductsTable::FILTER_KIND, 'unknown', []],
            [CategoryProductsTable::FILTER_AVAILABILITY, 'unknown', []],
            [CategoryProductsTable::FILTER_KIND, 1, []],
            [CategoryProductsTable::FILTER_AVAILABILITY, true, []],
        ] as [$key, $value, $expected]) {
            $window = $table->getPage(new TableQueryDTO(sort: $sort, filter: $categoryFilter + [$key => $value]));
            self::assertSame($expected, self::keys($window), $key . '=' . var_export($value, true));
            self::assertSame(count($expected), $window->totalCount);
        }
    }

    /** Each exact facet lifts its own filter and keeps the other filter, category and search. */
    public function testTheFacetsCountEachOptionWithoutItsOwnFilter(): void
    {
        $category = self::category();
        $otherCategory = self::category();
        $warehouse = self::warehouse();
        $inStock = self::productIn($category, ProductKind::STOCK, 'Rose seeds', 1_000, 1_000);
        self::productIn($category, ProductKind::STOCK, 'Rose bulbs', 2_000, 1_000);
        $free = self::productIn($category, ProductKind::UNIQUE, 'Rose vase', 3_000, 1_000);
        $otherName = self::productIn($category, ProductKind::STOCK, 'Tulip bulbs', 4_000, 1_000);
        $otherCategoryStock = self::productIn($otherCategory, ProductKind::STOCK, 'Rose bush', 5_000, 1_000);
        Hilos::$db->productWarehouseStocks->actions->setUnits($inStock, $warehouse, 10);
        Hilos::$db->productWarehouseStocks->actions->place($free, $warehouse);
        Hilos::$db->productWarehouseStocks->actions->setUnits($otherName, $warehouse, 3);
        Hilos::$db->productWarehouseStocks->actions->setUnits($otherCategoryStock, $warehouse, 3);

        $table = new CategoryProductsTable();
        $query = new TableQueryDTO(search: 'Rose', limit: 1, filter: [
            CategoryProductsTable::FILTER_CATEGORY => $category,
            CategoryProductsTable::FILTER_KIND => ProductKind::STOCK->value,
            CategoryProductsTable::FILTER_AVAILABILITY => CategoryProductsTable::AVAILABILITY_AVAILABLE,
        ]);

        $facets = $table->facetCounts($table->scopeSearch($query), [
            CategoryProductsTable::FILTER_CATEGORY => [$category, $otherCategory],
            CategoryProductsTable::FILTER_KIND => [ProductKind::STOCK->value, ProductKind::UNIQUE->value],
            CategoryProductsTable::FILTER_AVAILABILITY => [
                CategoryProductsTable::AVAILABILITY_AVAILABLE,
                CategoryProductsTable::AVAILABILITY_OUT_OF_STOCK,
            ],
        ]);

        self::assertEquals([
            CategoryProductsTable::FILTER_KIND => self::facet(2, [
                ProductKind::STOCK->value => 1,
                ProductKind::UNIQUE->value => 1,
            ]),
            CategoryProductsTable::FILTER_AVAILABILITY => self::facet(2, [
                CategoryProductsTable::AVAILABILITY_AVAILABLE => 1,
                CategoryProductsTable::AVAILABILITY_OUT_OF_STOCK => 1,
            ]),
        ], $facets);
    }

    /** Stock facts redraw a mass-produced item, which leaves an available-only window at zero units. */
    public function testStockChangesRedrawTheProductAndChangeItsWindowMembership(): void
    {
        $category = self::category();
        $warehouse = self::warehouse();
        $product = self::productIn($category, ProductKind::STOCK, 'Tulip bulbs', 3_000, 1_000);
        $table = new CategoryProductsTable();
        $available = new TableQueryDTO(filter: [
            CategoryProductsTable::FILTER_CATEGORY => $category,
            CategoryProductsTable::FILTER_AVAILABILITY => CategoryProductsTable::AVAILABILITY_AVAILABLE,
        ]);

        Hilos::$db->productWarehouseStocks->actions->setUnits($product, $warehouse, 10);
        $stock = self::storedStock($product, $warehouse);
        $stockId = (string) $stock[EntityProductWarehouseStock::id];
        $created = SourceChange::dbCreated(EcommerceShopDbContext::productWarehouseStocks, $stockId, $stock);
        self::assertSame(
            [[TableMutationType::Update, $product, self::row($product, 10, 'in_stock')]],
            self::described($table->buildMutationsForSourceEvent($created)),
        );
        self::assertTrue($table->containsRow($product, $available));

        Hilos::$db->productWarehouseStocks->actions->setUnits($product, $warehouse, 3);
        $changed = SourceChange::dbUpdated(
            EcommerceShopDbContext::productWarehouseStocks,
            $stockId,
            [EntityProductWarehouseStock::units => 3],
            previous: [EntityProductWarehouseStock::units => 10],
        );
        self::assertSame(
            [[TableMutationType::Update, $product, self::row($product, 3, 'low')]],
            self::described($table->buildMutationsForSourceEvent($changed)),
        );
        self::assertTrue($table->containsRow($product, $available));

        $previous = Hilos::$db->productWarehouseStocks[(int) $stockId]->toArray();
        Hilos::$db->productWarehouseStocks->actions->setUnits($product, $warehouse, 0);
        $removed = SourceChange::dbDeleted(EcommerceShopDbContext::productWarehouseStocks, $stockId, $previous);
        self::assertSame(
            [[TableMutationType::Update, $product, self::row($product, 0, 'out_of_stock')]],
            self::described($table->buildMutationsForSourceEvent($removed)),
        );
        self::assertFalse($table->containsRow($product, $available));
    }

    /** Price edits redraw a product, and creation, category transfers and deletion change window membership. */
    public function testProductFactsRedrawAndMoveRowsBetweenCategoryWindows(): void
    {
        $category = self::category();
        $destination = self::category();
        $product = self::productIn($category, ProductKind::STOCK, 'Tulip bulbs', 3_000, 1_000);
        $table = new CategoryProductsTable();
        $window = new TableQueryDTO(filter: [CategoryProductsTable::FILTER_CATEGORY => $category]);
        $otherWindow = new TableQueryDTO(filter: [CategoryProductsTable::FILTER_CATEGORY => $destination]);
        $before = self::storedProduct($product);
        $this->asProductsLibrary(
            static fn () => Hilos::$db->products[$product]->actions->update('Tulip bulbs', $category, ProductKind::STOCK, 2_000, 'Bulbs', null),
        );
        $after = self::storedProduct($product);
        $edited = $table->buildMutationsForSourceEvent(SourceChange::dbUpdated(
            EcommerceShopDbContext::products,
            (string) $product,
            $after,
            previous: $before,
        ));
        self::assertSame([[TableMutationType::Update, $product, self::row($product, 0, 'out_of_stock')]], self::described($edited));
        self::assertSame(2_000, $edited[0]->row?->toArray()[CategoryProductTableRow::price]);

        $new = self::productIn($category, ProductKind::STOCK, 'Rose seeds', 1_000, 1_000);
        $newRow = self::storedProduct($new);
        $created = SourceChange::dbCreated(EcommerceShopDbContext::products, (string) $new, $newRow);
        self::assertSame(
            [[TableMutationType::Create, $new, self::row($new, 0, 'out_of_stock')]],
            self::described($table->buildMutationsForSourceEvent($created)),
        );
        self::assertTrue($table->containsRow($new, $window));
        $elsewhere = self::productIn($destination, ProductKind::STOCK, 'Dahlia bulbs', 4_000, 1_000);
        $otherCreated = SourceChange::dbCreated(EcommerceShopDbContext::products, (string) $elsewhere, self::storedProduct($elsewhere));
        self::assertSame(
            [[TableMutationType::Create, $elsewhere, self::row($elsewhere, 0, 'out_of_stock')]],
            self::described($table->buildMutationsForSourceEvent($otherCreated)),
        );
        self::assertFalse($table->containsRow($elsewhere, $window));
        self::assertTrue($table->containsRow($elsewhere, $otherWindow));

        self::assertTrue($table->containsRow($product, $window));
        self::assertFalse($table->containsRow($product, $otherWindow));
        $this->asProductsLibrary(
            static fn () => Hilos::$db->products[$product]->actions->update('Tulip bulbs', $destination, ProductKind::STOCK, 2_000, 'Bulbs', null),
        );
        $moved = SourceChange::dbUpdated(
            EcommerceShopDbContext::products,
            (string) $product,
            [EntityProduct::category_id => $destination],
            previous: [EntityProduct::category_id => $category],
        );
        self::assertSame(
            [[TableMutationType::Update, $product, self::row($product, 0, 'out_of_stock')]],
            self::described($table->buildMutationsForSourceEvent($moved)),
        );
        self::assertFalse($table->containsRow($product, $window));
        self::assertTrue($table->containsRow($product, $otherWindow));

        Database::sql('DELETE FROM product WHERE id = ?', [$new]);
        DbSyncApplicator::applyDeleted(new DbSyncDeletedSignalData(EcommerceShopDbContext::products, (string) $new, $newRow));
        $deleted = SourceChange::dbDeleted(EcommerceShopDbContext::products, (string) $new, $newRow);
        self::assertSame([[TableMutationType::Delete, $new, null]], self::described($table->buildMutationsForSourceEvent($deleted)));
        self::assertFalse($table->containsRow($new, $window));
    }

    /** A unique piece appears when placed and leaves again when its warehouse stock row is taken away. */
    public function testAUniquePieceOnlyHasARowWhilePlaced(): void
    {
        $category = self::category();
        $warehouse = self::warehouse();
        $product = self::productIn($category, ProductKind::UNIQUE, 'Blue vase', 3_000, 1_000);
        $table = new CategoryProductsTable();
        $window = new TableQueryDTO(filter: [CategoryProductsTable::FILTER_CATEGORY => $category]);
        $created = SourceChange::dbCreated(EcommerceShopDbContext::products, (string) $product, self::storedProduct($product));
        self::assertNull($table->buildMutationForSourceEvent($created));
        self::assertNull($table->findRow($product));
        self::assertFalse($table->containsRow($product, $window));

        Hilos::$db->productWarehouseStocks->actions->place($product, $warehouse);
        $stock = self::storedStock($product, $warehouse);
        $stockId = (string) $stock[EntityProductWarehouseStock::id];
        $placed = SourceChange::dbCreated(EcommerceShopDbContext::productWarehouseStocks, $stockId, $stock);
        $row = self::row($product, 1, 'free', 'Product of the case');
        self::assertSame([[TableMutationType::Update, $product, $row]], self::described($table->buildMutationsForSourceEvent($placed)));
        self::assertTrue($table->containsRow($product, $window));
        self::assertSame($row, $table->findRow($product)?->toArray());

        Database::sql('DELETE FROM product_warehouse_stock WHERE id = ?', [(int) $stockId]);
        DbSyncApplicator::applyDeleted(new DbSyncDeletedSignalData(EcommerceShopDbContext::productWarehouseStocks, $stockId, $stock));
        $takenAway = SourceChange::dbDeleted(EcommerceShopDbContext::productWarehouseStocks, $stockId, $stock);
        self::assertSame([[TableMutationType::Delete, $product, null]], self::described($table->buildMutationsForSourceEvent($takenAway)));
        self::assertFalse($table->containsRow($product, $window));
        self::assertNull($table->findRow($product));
    }

    /** Renaming the category or the warehouse of a visible piece produces no product mutation. */
    public function testCategoryAndWarehouseFactsDoNotRedrawProducts(): void
    {
        $category = self::category('Roses');
        $warehouse = self::warehouse('Kyiv');
        $product = self::productIn($category, ProductKind::UNIQUE, 'Blue vase', 3_000, 1_000);
        Hilos::$db->productWarehouseStocks->actions->place($product, $warehouse);
        $table = new CategoryProductsTable();
        self::assertNotNull($table->findRow($product));

        Database::sql('UPDATE category SET name = ? WHERE id = ?', ['Flowers', $category]);
        $renamedCategory = self::renamedFixture(EcommerceShopDbContext::categories, $category, EntityCategory::name, 'Roses', 'Flowers');
        self::assertSame([], $table->buildMutationsForSourceEvent($renamedCategory));

        Database::sql('UPDATE warehouse SET name = ? WHERE id = ?', ['Podil', $warehouse]);
        $renamedWarehouse = self::renamedFixture(EcommerceShopDbContext::warehouses, $warehouse, EntityWarehouse::name, 'Kyiv', 'Podil');
        self::assertSame([], $table->buildMutationsForSourceEvent($renamedWarehouse));
    }

    /**
     * @param TableSnapshotDTO $window Window of typed product rows
     * @return list<int|string> Row keys in window order
     */
    private static function keys(TableSnapshotDTO $window): array
    {
        return array_map(static fn (AbstractTableRow $row): int|string => $row->requireRowKey(), $window->rows);
    }

    /**
     * @param int $id Product id
     * @param int $units Expected units
     * @param string $availability Expected availability on the wire
     * @param string $pictureAlt Expected alternative text on the wire
     * @return array<string, int|string> Full storefront row, with the product fields read independently
     */
    private static function row(int $id, int $units, string $availability, string $pictureAlt = ''): array
    {
        $stored = self::storedProduct($id);

        return [
            'id' => $id,
            'slug' => $stored[EntityProduct::slug],
            'name' => $stored[EntityProduct::name],
            'pictureUrl' => HilosFiles::downloadPath((int) $stored[EntityProduct::picture_file_id], CatalogPictureUploadTarget::VARIANT_TILE),
            'pictureAlt' => $pictureAlt,
            'kind' => $stored[EntityProduct::kind],
            'price' => (int) $stored[EntityProduct::price],
            'units' => $units,
            'availability' => $availability,
        ];
    }

    /**
     * @param int $count Products matching the other filters
     * @param array<array-key, int> $options Products each option leaves
     * @return array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>} Exact facet counts
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
     * @param list<TableRowMutationDTO> $mutations Mutations built from a fact
     * @return list<array{TableMutationType, int|string, ?array<string, mixed>}> Mutation types, row keys and payloads
     */
    private static function described(array $mutations): array
    {
        return array_map(
            static fn (TableRowMutationDTO $mutation): array => [$mutation->type, $mutation->rowKey, $mutation->row?->toArray()],
            $mutations,
        );
    }

    /**
     * @param int $id Product id
     * @return array<string, mixed> Product row in stored columns
     */
    private static function storedProduct(int $id): array
    {
        return Database::sql('SELECT * FROM product WHERE id = ?', [$id])->rows()[0];
    }

    /**
     * @param int $product Product id
     * @param int $warehouse Warehouse id
     * @return array<string, int> Stock row in stored columns
     */
    private static function storedStock(int $product, int $warehouse): array
    {
        $rows = Database::sql('SELECT * FROM product_warehouse_stock WHERE product_id = ? AND warehouse_id = ?', [$product, $warehouse])->rows();
        self::assertCount(1, $rows);

        return [
            EntityProductWarehouseStock::id => (int) $rows[0][EntityProductWarehouseStock::id],
            EntityProductWarehouseStock::product_id => (int) $rows[0][EntityProductWarehouseStock::product_id],
            EntityProductWarehouseStock::warehouse_id => (int) $rows[0][EntityProductWarehouseStock::warehouse_id],
            EntityProductWarehouseStock::units => (int) $rows[0][EntityProductWarehouseStock::units],
        ];
    }

    /**
     * Mirrors a fixture rename in any loaded ORM row before building the fact the table sees.
     *
     * @param string $collection Collection of the renamed row
     * @param int $id Row id
     * @param string $field Stored name column
     * @param string $before Former name
     * @param string $after New name
     * @return SourceChange Update fact
     */
    private static function renamedFixture(string $collection, int $id, string $field, string $before, string $after): SourceChange
    {
        $row = [$field => $after];
        $previous = [$field => $before];
        DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData($collection, (string) $id, $row, previous: $previous));

        return SourceChange::dbUpdated($collection, (string) $id, $row, previous: $previous);
    }
}

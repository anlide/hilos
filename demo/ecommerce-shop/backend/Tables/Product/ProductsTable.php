<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Product;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\DTO\ProductStockTotals;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\MultiRowViewportTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\CollectionNotFullyLoadedException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Files\HilosFiles;

/**
 * The shop's products, recently changed first, each with its category, its units in the
 * warehouses and how much of it there is to sell.
 *
 * The catalog is read whole from the products library, which holds the set, and is filtered by
 * category, kind and availability, searched by name and ordered in memory; every filter counts
 * its options the same way. A product row is redrawn when the product, one of its stock rows,
 * its unique piece's warehouse or its category is written; who may open the table is decided by
 * the page that binds it.
 */
final class ProductsTable extends TableDefinition implements MultiRowViewportTable
{
    /** Canonical table key under which the project registers this table in its TableContext. */
    public const string TABLE = 'products';

    /** Filter-map key: narrow the products to one category, by its id. */
    public const string FILTER_CATEGORY = 'category';

    /** Filter-map key: narrow the products to one kind, a {@see ProductKind} value. */
    public const string FILTER_KIND = 'kind';

    /** Filter-map key: narrow the products to one availability, a {@see ProductAvailability} value. */
    public const string FILTER_AVAILABILITY = 'availability';

    /**
     * The last three rows only watch: they make a page process interested in the stock rows, the
     * warehouses and the categories, so that their facts reach the window and redraw the products
     * they name.
     */
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            EcommerceShopBrowserSource::DB_PRODUCTS,
            EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS,
            EcommerceShopBrowserSource::DB_WAREHOUSES,
            EcommerceShopBrowserSource::DB_CATEGORIES,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_PRODUCTS,
                BrowserTableFieldKey::ROW_KEY => ObjectProduct::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectProduct::id => ProductTableRow::id,
                    ObjectProduct::name => ProductTableRow::name,
                    ObjectProduct::categoryId => ProductTableRow::categoryId,
                    ObjectProduct::kind => ProductTableRow::kind,
                    ObjectProduct::price => ProductTableRow::price,
                    ObjectProduct::pictureFileId,
                    ObjectProduct::changedAt => ProductTableRow::changedAt,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    ProductTableRow::pictureUrl,
                    ProductTableRow::categoryName,
                    ProductTableRow::units,
                    ProductTableRow::warehouseName,
                    ProductTableRow::availability,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS,
                BrowserTableFieldKey::ROW_KEY => ObjectProductWarehouseStock::productId,
                BrowserTableFieldKey::FIELDS => [
                    ObjectProductWarehouseStock::id,
                    ObjectProductWarehouseStock::productId,
                    ObjectProductWarehouseStock::warehouseId,
                    ObjectProductWarehouseStock::units,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_WAREHOUSES,
                BrowserTableFieldKey::ROW_KEY => ObjectWarehouse::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectWarehouse::id,
                    ObjectWarehouse::name,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_CATEGORIES,
                BrowserTableFieldKey::ROW_KEY => ObjectCategory::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectCategory::id,
                    ObjectCategory::name,
                ],
            ],
        ],
    ];

    /** Wire slot the row payload rides under. */
    private const string ROW_SLOT = 'product';

    /** Filters whose options this table counts: all three, the rows being in memory already. */
    private const array FACETED_FILTERS = [self::FILTER_CATEGORY, self::FILTER_KIND, self::FILTER_AVAILABILITY];

    /**
     * @return ?TableSortOrderDTO First window with the most recently changed product first
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(ProductTableRow::changedAt, TableConstants::ORDER_DESC));
    }

    /**
     * A change of the products collection redraws that product; anything else is ignored.
     *
     * A deletion names the removed row's id. A create or an update is read back by the row's
     * id, and a row that is already gone is ignored.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Product row mutation, or null when the change does not affect this table
     * @throws DatabaseException When the product, its category, its stock rows or its warehouse cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== EcommerceShopDbContext::products) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, (int) $change->sourceId);
        }

        if (!isset(Hilos::$db->products[(int) $change->sourceId])) {
            return null;
        }
        $product = Hilos::$db->products[(int) $change->sourceId];

        return $this->mutation($change->mutationType, $product->id, $this->rowOfProduct($product));
    }

    /**
     * A product fact redraws that product; a stock row, a warehouse or a category redraws every product it bears on.
     *
     * A stock row names its product in the current row, the fact's row and its previous row, in
     * either row vocabulary: a deleted row names it only in the fact. A renamed warehouse
     * redraws the unique pieces it keeps, the only rows that show its name; a renamed category
     * redraws its products. A created warehouse or category keeps nothing yet, and only an empty
     * one can be deleted, so neither redraws anything. The products of one fact are redrawn
     * from one read of their stock rows, each product once.
     *
     * @param SourceChange $change Source fact from the products, their stock rows, the warehouses or the categories
     * @return list<TableRowMutationDTO> Current product rows, without duplicates or vanished products
     * @throws DatabaseException When a product, a category, a warehouse or a stock row cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a matched row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched row is rejected by a non-manual collection
     */
    public function buildMutationsForSourceEvent(SourceChange $change): array
    {
        if ($change->kind !== SourceChange::KIND_DB) {
            return [];
        }
        if ($change->sourceKey === EcommerceShopDbContext::products) {
            $mutation = $this->buildMutationForSourceEvent($change);

            return $mutation === null ? [] : [$mutation];
        }
        if ($change->sourceKey === EcommerceShopDbContext::productWarehouseStocks) {
            return $this->updatesOf($this->productsOfStockFact($change));
        }
        if ($change->mutationType !== TableMutationType::Update) {
            return [];
        }
        if ($change->sourceKey === EcommerceShopDbContext::warehouses) {
            return $this->updatesOf($this->uniquePiecesIn((int) $change->sourceId));
        }
        if ($change->sourceKey === EcommerceShopDbContext::categories) {
            return $this->updatesOf(
                iterator_to_array(
                    Hilos::$db->products->whereColumnIs(ObjectProduct::categoryId, (int) $change->sourceId),
                    false,
                ),
            );
        }

        return [];
    }

    /**
     * @param AbstractTableRow $row Product row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * The six stored fields are columns of product and the two names columns of category and
     * warehouse, none of them personal. The picture's address, the units and the availability
     * are computed, nonpersonal facts.
     *
     * @return array<string, WireField> Row field to where it comes from
     */
    public function wireFields(): array
    {
        return [
            ProductTableRow::id => WireField::column(EcommerceShopDbContext::products, ObjectProduct::id),
            ProductTableRow::name => WireField::column(EcommerceShopDbContext::products, ObjectProduct::name),
            ProductTableRow::pictureUrl => WireField::notPersonal(),
            ProductTableRow::categoryId => WireField::column(EcommerceShopDbContext::products, ObjectProduct::categoryId),
            ProductTableRow::categoryName => WireField::column(EcommerceShopDbContext::categories, ObjectCategory::name),
            ProductTableRow::kind => WireField::column(EcommerceShopDbContext::products, ObjectProduct::kind),
            ProductTableRow::price => WireField::column(EcommerceShopDbContext::products, ObjectProduct::price),
            ProductTableRow::units => WireField::notPersonal(),
            ProductTableRow::warehouseName => WireField::column(EcommerceShopDbContext::warehouses, ObjectWarehouse::name),
            ProductTableRow::availability => WireField::notPersonal(),
            ProductTableRow::changedAt => WireField::column(EcommerceShopDbContext::products, ObjectProduct::changedAt),
        ];
    }

    /**
     * Counts how many products each option of the category, kind and availability filters would leave.
     *
     * Each option is counted against the set the window shows with its own filter set to that
     * option, the way {@see TableFacetTally::forFilters()} describes. The catalog is projected
     * once for every set, and nothing is capped: the rows are in hand, and the count is exact.
     *
     * @param TableQueryDTO $query Window query whose search and filters describe the set, its search scoped
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts of the
     *     category, kind and availability options that were asked about
     * @throws DatabaseException When the products, their categories, stock rows or warehouses cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws CollectionNotFullyLoadedException When this process does not hold the whole catalog
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        $rows = $this->allRows();

        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, array_flip(self::FACETED_FILTERS)),
            fn(TableQueryDTO $set): TableFacetCountDTO => new TableFacetCountDTO(
                $this->filterInMemory($this->narrow($rows, $set), $set)->totalCount,
                true,
            ),
        );
    }

    /**
     * Whether the product is in the set this window would show.
     *
     * The one product is narrowed by the window's filters and searched the same way the window
     * is, so a new product under an active filter or search is announced only when the window
     * would keep it. The catalog is not read for it.
     *
     * @param string|int $rowKey Decimal product id
     * @param TableQueryDTO $query Window query whose search and filters describe the set
     * @return ?bool Whether the product is in the set; this table always knows
     * @throws DatabaseException When the product, its category, its stock rows or its warehouse cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        $product = $this->productOfKey($rowKey);
        if ($product === null) {
            return false;
        }

        return $this->containsRowInMemory(
            $this->narrow([$this->rowOfProduct($product)->toArray()], $query),
            $product->id,
            $this->scopeSearch($query),
        );
    }

    /**
     * Reads the row of one product by its key, without reading the catalog.
     *
     * @param string|int $rowKey Decimal product id
     * @return ?AbstractTableRow The product's row, or null when no product holds the key
     * @throws DatabaseException When the product, its category, its stock rows or its warehouse cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    public function findRow(string|int $rowKey): ?AbstractTableRow
    {
        $product = $this->productOfKey($rowKey);

        return $product === null ? null : $this->rowOfProduct($product);
    }

    /**
     * The whole catalog, narrowed by the window's filters, then searched and ordered in memory.
     *
     * @param TableQueryDTO $query Window query, its search already scoped
     * @return TableSnapshotDTO Products window
     * @throws DatabaseException When the products, their categories, stock rows or warehouses cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws CollectionNotFullyLoadedException When this process does not hold the whole catalog
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->narrow($this->allRows(), $query), $query);
    }

    /**
     * @return array<string, string> The name, the category's name, the price and the moment of the last edit, each ordered by itself
     */
    protected function sortableFields(): array
    {
        return [
            ProductTableRow::name => ProductTableRow::name,
            ProductTableRow::categoryName => ProductTableRow::categoryName,
            ProductTableRow::price => ProductTableRow::price,
            ProductTableRow::changedAt => ProductTableRow::changedAt,
        ];
    }

    /**
     * @return array<string, string> The name, searched as a substring in memory
     */
    protected function searchableFields(): array
    {
        return [
            ProductTableRow::name => ProductTableRow::name,
        ];
    }

    /**
     * Configures the row shape used by the products table.
     */
    protected function init(): void
    {
        $this->setRowClass(ProductTableRow::class);
    }

    /**
     * Every product of the catalog as a row payload, its stock totals read in one call.
     *
     * The catalog is walked as the set the products library holds whole; a process that does not
     * hold it is refused loudly rather than shown an empty or partial window.
     *
     * @return list<array<string, mixed>> Product row payloads
     * @throws DatabaseException When the products, their categories, stock rows or warehouses cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws CollectionNotFullyLoadedException When this process does not hold the whole catalog
     */
    private function allRows(): array
    {
        $products = iterator_to_array(Hilos::$db->products, false);
        $totals = Hilos::$db->productWarehouseStocks->totalsOfProducts(
            ...array_map(static fn(Product $product): int => $product->id, $products),
        );

        return array_map(
            fn(Product $product): array => $this->rowFromProduct($product, $totals->of($product->id))->toArray(),
            $products,
        );
    }

    /**
     * @param Product $product Product to project
     * @return ProductTableRow The product with the totals of its own stock rows
     * @throws DatabaseException When its category, its stock rows or its warehouse cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    private function rowOfProduct(Product $product): ProductTableRow
    {
        return $this->rowFromProduct(
            $product,
            Hilos::$db->productWarehouseStocks->totalsOfProducts($product->id)->of($product->id),
        );
    }

    /**
     * Projects one product with the totals of its stock rows.
     *
     * The category and the warehouse are read by key without a fallback: the database refuses to
     * remove a category or a warehouse a product still names.
     *
     * @param Product $product Product to project
     * @param ProductStockTotals $totals Totals of the product's stock rows
     * @return ProductTableRow The product as the table shows it
     * @throws DatabaseException When its category or its warehouse cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     */
    private function rowFromProduct(Product $product, ProductStockTotals $totals): ProductTableRow
    {
        return new ProductTableRow(
            id: $product->id,
            name: $product->name,
            pictureUrl: HilosFiles::downloadPath($product->pictureFileId, CatalogPictureUploadTarget::VARIANT_XS),
            categoryId: $product->categoryId,
            categoryName: Hilos::$db->categories[$product->categoryId]->name,
            kind: $product->kind->value,
            price: $product->price,
            units: $totals->unitCount,
            warehouseName: $product->kind === ProductKind::UNIQUE && $totals->warehouseId !== null
                ? Hilos::$db->warehouses[$totals->warehouseId]->name
                : null,
            availability: ProductAvailability::of($product->kind, $totals->unitCount)->value,
            changedAt: $product->changedAt,
        );
    }

    /**
     * Updates of the rows of several products, their stock totals read in one call.
     *
     * @param list<Product> $products Products to redraw, each at most once
     * @return list<TableRowMutationDTO> One update per product
     * @throws DatabaseException When their categories, stock rows or warehouses cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    private function updatesOf(array $products): array
    {
        $totals = Hilos::$db->productWarehouseStocks->totalsOfProducts(
            ...array_map(static fn(Product $product): int => $product->id, $products),
        );

        return array_map(
            fn(Product $product): TableRowMutationDTO => $this->mutation(
                TableMutationType::Update,
                $product->id,
                $this->rowFromProduct($product, $totals->of($product->id)),
            ),
            $products,
        );
    }

    /**
     * The products a stock fact names that still exist, each once.
     *
     * @param SourceChange $change Fact of a stock row
     * @return list<Product> Products named by the current row, the fact's row or its previous row
     * @throws DatabaseException When a stock row or a product cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     */
    private function productsOfStockFact(SourceChange $change): array
    {
        $stock = $change->mutationType === TableMutationType::Delete
            ? null
            : Hilos::$db->productWarehouseStocks[(int) $change->sourceId] ?? null;
        $productIds = [];
        foreach ([$stock?->toArray() ?? [], $change->row, $change->previous] as $row) {
            $id = $row[ObjectProductWarehouseStock::productId] ?? $row[EntityProductWarehouseStock::product_id] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $productIds[(int) $id] = true;
            }
        }

        $products = [];
        foreach (array_keys($productIds) as $productId) {
            if (isset(Hilos::$db->products[$productId])) {
                $products[] = Hilos::$db->products[$productId];
            }
        }

        return $products;
    }

    /**
     * The unique pieces one warehouse keeps, each once.
     *
     * @param int $warehouseId Id of the warehouse
     * @return list<Product> Unique pieces with a stock row in the warehouse
     * @throws DatabaseException When a stock row or a product cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    private function uniquePiecesIn(int $warehouseId): array
    {
        $products = [];
        foreach (Hilos::$db->productWarehouseStocks->whereColumnIs(ObjectProductWarehouseStock::warehouseId, $warehouseId) as $stock) {
            if (isset(Hilos::$db->products[$stock->productId]) && !isset($products[$stock->productId])) {
                $product = Hilos::$db->products[$stock->productId];
                if ($product->kind === ProductKind::UNIQUE) {
                    $products[$stock->productId] = $product;
                }
            }
        }

        return array_values($products);
    }

    /**
     * Reads the product a row key names.
     *
     * @param string|int $rowKey Decimal product id
     * @return ?Product The product, or null when the key is no product id or no product holds it
     * @throws DatabaseException When the product cannot be read
     * @throws LogicException When the products collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     */
    private function productOfKey(string|int $rowKey): ?Product
    {
        $id = filter_var($rowKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || (string) $id !== (string) $rowKey || !isset(Hilos::$db->products[$id])) {
            return null;
        }

        return Hilos::$db->products[$id];
    }

    /**
     * Applies the category, kind and availability filters of the window.
     *
     * A filter that is absent, null or empty narrows nothing. A category is a positive id, as a
     * number or as decimal digits; a kind and an availability are their values as text. A filter
     * of any other shape names no product, and the set is empty rather than refused.
     *
     * @param list<array<string, mixed>> $rows Rows of the catalog
     * @param TableQueryDTO $query Window query
     * @return list<array<string, mixed>> Rows the window asked for
     */
    private function narrow(array $rows, TableQueryDTO $query): array
    {
        $fields = [
            self::FILTER_CATEGORY => ProductTableRow::categoryId,
            self::FILTER_KIND => ProductTableRow::kind,
            self::FILTER_AVAILABILITY => ProductTableRow::availability,
        ];
        foreach ($fields as $filter => $field) {
            $value = $query->filter[$filter] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $wanted = $filter === self::FILTER_CATEGORY ? self::categoryIdOf($value) : (is_string($value) ? $value : null);
            if ($wanted === null) {
                return [];
            }
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row[$field] === $wanted));
        }

        return $rows;
    }

    /**
     * Reads a category filter value as a category id.
     *
     * @param mixed $value Category filter value from the window query
     * @return ?int Category id, or null when the value is no positive id
     */
    private static function categoryIdOf(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}

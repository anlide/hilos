<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Product;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\DTO\ProductStockTotalsCollection;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
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
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Files\HilosFiles;

/**
 * The storefront products of one category, with unique pieces visible only while kept in a warehouse.
 *
 * Reads the selected category and its stock totals even in a process that holds neither collection
 * whole. The binding page decides who can open the table and presets the first window from its address.
 */
final class CategoryProductsTable extends TableDefinition implements MultiRowViewportTable
{
    public const string TABLE = 'categoryProducts';
    public const string FILTER_CATEGORY = 'category';
    public const string FILTER_KIND = 'kind';
    public const string FILTER_AVAILABILITY = 'availability';
    public const string AVAILABILITY_AVAILABLE = 'available';
    public const string AVAILABILITY_OUT_OF_STOCK = ProductAvailability::OUT_OF_STOCK->value;

    /** Category, picture and description changes affect the window. Stock facts redraw their products. */
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            EcommerceShopBrowserSource::DB_PRODUCTS,
            EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_PRODUCTS,
                BrowserTableFieldKey::ROW_KEY => ObjectProduct::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectProduct::id => CategoryProductTableRow::id,
                    ObjectProduct::slug => CategoryProductTableRow::slug,
                    ObjectProduct::name => CategoryProductTableRow::name,
                    ObjectProduct::kind => CategoryProductTableRow::kind,
                    ObjectProduct::price => CategoryProductTableRow::price,
                    ObjectProduct::categoryId,
                    ObjectProduct::pictureFileId,
                    ObjectProduct::description,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    CategoryProductTableRow::pictureUrl,
                    CategoryProductTableRow::pictureAlt,
                    CategoryProductTableRow::units,
                    CategoryProductTableRow::availability,
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
        ],
    ];

    private const string ROW_SLOT = 'product';
    private const array FACETED_FILTERS = [self::FILTER_KIND, self::FILTER_AVAILABILITY];

    /**
     * @return TableSortOrderDTO First window with the lowest price first
     */
    public function defaultSort(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(CategoryProductTableRow::price, TableConstants::ORDER_ASC));
    }

    /**
     * An unplaced unique piece is absent from the storefront, including in live changes.
     *
     * @param SourceChange $change Source change that may affect a product row
     * @return ?TableRowMutationDTO Product mutation, or null when there is no row to announce or remove
     * @throws DatabaseException When the product or its stock cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock item is rejected by a non-manual collection
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== EcommerceShopDbContext::products) {
            return null;
        }
        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, (int) $change->sourceId);
        }

        $product = ProductFacts::productOfKey($change->sourceId);
        if ($product === null) {
            return null;
        }

        $row = $this->rowOfProduct($product);
        if ($row === null) {
            return $change->mutationType === TableMutationType::Update
                ? $this->mutation(TableMutationType::Delete, $product->id)
                : null;
        }

        return $this->mutation($change->mutationType, $product->id, $row);
    }

    /**
     * Stock facts redraw each affected product once, with their totals read together.
     *
     * @param SourceChange $change Product or stock fact
     * @return list<TableRowMutationDTO> Current visible rows and removals from the storefront
     * @throws DatabaseException When a product or its stock cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock item is rejected by a non-manual collection
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
            return $this->updatesOf(ProductFacts::productsOfStockFact($change));
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
     * Counts kind and availability choices exactly within the category and current search.
     *
     * @param TableQueryDTO $query Window query with its search already scoped
     * @param array<string, list<int|float|string|bool>> $wanted Options to count by filter key
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts by filter key
     * @throws DatabaseException When the products or their stock cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws TableSearchNotSupportedException When a search arrives without a declared search field
     * @throws TableSearchFieldUnknownException When the searched field is absent from the row set
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): array
    {
        $rows = $this->rowsOfCategory(self::categoryIdOf($query->filter[self::FILTER_CATEGORY] ?? null));

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
     * @param string|int $rowKey Decimal product id
     * @param TableQueryDTO $query Window query whose filters and search determine membership
     * @return bool Whether the product is visible in the window's category, filters and search
     * @throws DatabaseException When the product or its stock cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock item is rejected by a non-manual collection
     * @throws TableSearchNotSupportedException When a search arrives without a declared search field
     * @throws TableSearchFieldUnknownException When the searched field is absent from the row set
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): bool
    {
        $categoryId = self::categoryIdOf($query->filter[self::FILTER_CATEGORY] ?? null);
        if ($categoryId === null) {
            return false;
        }

        $product = ProductFacts::productOfKey($rowKey);
        if ($product === null || $product->categoryId !== $categoryId) {
            return false;
        }

        $row = $this->rowOfProduct($product);

        return $row !== null && $this->containsRowInMemory(
            $this->narrow([$row->toArray()], $query),
            $product->id,
            $this->scopeSearch($query),
        );
    }

    /**
     * @param string|int $rowKey Decimal product id
     * @return ?CategoryProductTableRow Visible product row, or null for a missing product or unplaced piece
     * @throws DatabaseException When the product or its stock cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock item is rejected by a non-manual collection
     */
    public function findRow(string|int $rowKey): ?CategoryProductTableRow
    {
        $product = ProductFacts::productOfKey($rowKey);

        return $product === null ? null : $this->rowOfProduct($product);
    }

    /**
     * @param TableQueryDTO $query Window query with its search already scoped
     * @return TableSnapshotDTO Filtered window of one category, empty without a valid category id
     * @throws DatabaseException When the products or their stock cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws TableSearchNotSupportedException When a search arrives without a declared search field
     * @throws TableSearchFieldUnknownException When the searched field is absent from the row set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = $this->rowsOfCategory(self::categoryIdOf($query->filter[self::FILTER_CATEGORY] ?? null));

        return $this->filterInMemory($this->narrow($rows, $query), $query);
    }

    /**
     * @return array<string, string> Price in either direction
     */
    protected function sortableFields(): array
    {
        return [CategoryProductTableRow::price => CategoryProductTableRow::price];
    }

    /**
     * @return array<string, string> Product name, searched as a substring
     */
    protected function searchableFields(): array
    {
        return [CategoryProductTableRow::name => CategoryProductTableRow::name];
    }

    /** Registers the storefront row payload. */
    protected function init(): void
    {
        $this->setRowClass(CategoryProductTableRow::class);
    }

    /**
     * @param mixed $value Category filter from the query
     * @return ?int Positive category id, including when sent as decimal digits
     */
    private static function categoryIdOf(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = filter_var(ltrim($value, '0'), FILTER_VALIDATE_INT);
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @param ?int $categoryId Selected category, or null for an empty set
     * @return list<array<string, int|string>> Visible product row payloads from that category
     * @throws DatabaseException When the products or their stock cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     */
    private function rowsOfCategory(?int $categoryId): array
    {
        if ($categoryId === null) {
            return [];
        }

        $products = iterator_to_array(Hilos::$db->products->whereColumnIs(ObjectProduct::categoryId, $categoryId), false);
        $totals = $this->totalsOf($products);
        $rows = [];
        foreach ($products as $product) {
            $row = $this->rowFromProduct($product, $totals->of($product->id)->unitCount);
            if ($row !== null) {
                $rows[] = $row->toArray();
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, int|string>> $rows Visible product rows in the selected category
     * @param TableQueryDTO $query Window query whose kind and availability filters apply
     * @return list<array<string, int|string>> Rows matching the supplied filters, empty for unsupported values
     */
    private function narrow(array $rows, TableQueryDTO $query): array
    {
        if (array_key_exists(self::FILTER_KIND, $query->filter)) {
            $kind = $query->filter[self::FILTER_KIND];
            if (!is_string($kind) || ProductKind::tryFrom($kind) === null) {
                return [];
            }
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row[CategoryProductTableRow::kind] === $kind));
        }

        if (!array_key_exists(self::FILTER_AVAILABILITY, $query->filter)) {
            return $rows;
        }

        $values = match ($query->filter[self::FILTER_AVAILABILITY]) {
            self::AVAILABILITY_AVAILABLE => [ProductAvailability::FREE->value, ProductAvailability::IN_STOCK->value, ProductAvailability::LOW->value],
            self::AVAILABILITY_OUT_OF_STOCK => [ProductAvailability::OUT_OF_STOCK->value],
            default => [],
        };

        return $values === [] ? [] : array_values(array_filter(
            $rows,
            static fn(array $row): bool => in_array($row[CategoryProductTableRow::availability], $values, true),
        ));
    }

    /**
     * @param list<Product> $products Products to redraw, each at most once
     * @return list<TableRowMutationDTO> One update or removal per product
     * @throws DatabaseException When the stock cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock item is rejected by a non-manual collection
     */
    private function updatesOf(array $products): array
    {
        $totals = $this->totalsOf($products);
        $mutations = [];
        foreach ($products as $product) {
            $row = $this->rowFromProduct($product, $totals->of($product->id)->unitCount);
            $mutations[] = $row === null
                ? $this->mutation(TableMutationType::Delete, $product->id)
                : $this->mutation(TableMutationType::Update, $product->id, $row);
        }

        return $mutations;
    }

    /**
     * @param list<Product> $products Products whose stock is needed as a batch
     * @return ProductStockTotalsCollection Stock totals keyed by product id
     * @throws DatabaseException When the stock cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock item is rejected by a non-manual collection
     */
    private function totalsOf(array $products): ProductStockTotalsCollection
    {
        return Hilos::$db->productWarehouseStocks->totalsOfProducts(
            ...array_map(static fn(Product $product): int => $product->id, $products),
        );
    }

    /**
     * @param Product $product Product to project
     * @return ?CategoryProductTableRow Visible row with its stock totals, or null for an unplaced unique piece
     * @throws DatabaseException When the stock cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type or the picture variant is undeclared
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock item is rejected by a non-manual collection
     */
    private function rowOfProduct(Product $product): ?CategoryProductTableRow
    {
        return $this->rowFromProduct(
            $product,
            Hilos::$db->productWarehouseStocks->totalsOfProducts($product->id)->of($product->id)->unitCount,
        );
    }

    /**
     * @param Product $product Product to project
     * @param int $units Total units in its stock rows
     * @return ?CategoryProductTableRow Visible row, or null for an unplaced unique piece
     * @throws InvalidArgumentException When the picture variant is undeclared or malformed
     */
    private function rowFromProduct(Product $product, int $units): ?CategoryProductTableRow
    {
        $availability = ProductAvailability::of($product->kind, $units);
        if ($availability === ProductAvailability::NOT_PLACED) {
            return null;
        }

        return new CategoryProductTableRow(
            id: $product->id,
            slug: $product->slug,
            name: $product->name,
            pictureUrl: HilosFiles::downloadPath($product->pictureFileId, CatalogPictureUploadTarget::VARIANT_TILE),
            pictureAlt: match ($product->kind) {
                ProductKind::UNIQUE => $product->description,
                ProductKind::STOCK => '',
            },
            kind: $product->kind->value,
            price: $product->price,
            units: $units,
            availability: $availability->value,
        );
    }
}

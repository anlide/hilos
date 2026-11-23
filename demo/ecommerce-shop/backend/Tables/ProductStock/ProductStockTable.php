<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\ProductStock;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Demo\EcommerceShop\Database\View\Item\Warehouse;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\WindowScopedViewportTable;
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
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * The stock of one product: a row for every warehouse of the shop and the units of the product it keeps.
 *
 * The window's subject is preset from the page address by {@see self::defaultFilter()} in the
 * {@see self::FILTER_PRODUCT} filter. Only a stock item has units in warehouses. A window opened on a
 * unique piece, on a product that does not exist, or on no product at all is an empty set. A warehouse
 * that keeps none of the product is a row with zero units, so a row stands for every warehouse while
 * the warehouse stands.
 *
 * The same warehouse is a different row in the window of each product, so a change is built for one
 * window at a time ({@see WindowScopedViewportTable}): a new, edited or removed warehouse adds, updates or
 * removes its row, and a stock row of the window's product updates the row of the warehouse it names —
 * to zero when the stock row is gone, the warehouse row staying. The warehouses are a few rows, so the
 * table reads them whole and searches and orders them in memory.
 */
final class ProductStockTable extends TableDefinition implements WindowScopedViewportTable
{
    /** Canonical table key under which the project registers this table in its TableContext. */
    public const string TABLE = 'productStock';

    /** Filter-map key: the id of the product the window is opened on. */
    public const string FILTER_PRODUCT = 'product';

    /** Wire slot the row payload rides under. */
    public const string ROW_SLOT = 'stock';

    /**
     * The warehouses anchor the rows; the stock rows join the row of the warehouse they name, so that
     * their facts reach the window and redraw the units of that warehouse.
     */
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            EcommerceShopBrowserSource::DB_WAREHOUSES,
            EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_WAREHOUSES,
                BrowserTableFieldKey::ROW_KEY => ObjectWarehouse::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectWarehouse::id => ProductStockTableRow::warehouseId,
                    ObjectWarehouse::name => ProductStockTableRow::warehouseName,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS,
                BrowserTableFieldKey::ROW_KEY => ObjectProductWarehouseStock::warehouseId,
                BrowserTableFieldKey::MANY => true,
                BrowserTableFieldKey::FIELDS => [ObjectProductWarehouseStock::warehouseId],
            ],
        ],
    ];

    /**
     * @return ?TableSortOrderDTO First window ordered by warehouse name ascending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(ProductStockTableRow::warehouseName));
    }

    /**
     * @param PageRouteParams $params Route params of the product opening this window
     * @return array{product: int} Product scope of the first window
     * @throws MissingPageRouteParamException When the product id is absent or empty
     * @throws InvalidPageRouteParamException When the product id is not a positive integer
     */
    public function defaultFilter(PageRouteParams $params): array
    {
        return [self::FILTER_PRODUCT => AdminProductPageSubscribeParams::fromPageRouteParams($params)->productId];
    }

    /**
     * Builds the row mutations one change makes in the window of one product.
     *
     * A warehouse change adds, updates or removes the row of that warehouse. A stock row is read in the
     * current row, the fact's row and its previous row, in either row vocabulary: a removed stock row names
     * its product and warehouse only in the fact. A stock row of another product is ignored.
     *
     * @param SourceChange $change Source change that may affect this table
     * @param TableQueryDTO $window Query the window was served by; its filter names the product
     * @return list<TableRowMutationDTO> Row mutations for this window, empty when the change does not touch it
     * @throws DatabaseException When the product, a warehouse or a stock row cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws HilosException When the kind of the product cannot be read
     */
    public function buildMutationsForWindow(SourceChange $change, TableQueryDTO $window): array
    {
        if ($change->kind !== SourceChange::KIND_DB) {
            return [];
        }
        $productId = $this->subjectIdOf($window);
        if ($productId === null) {
            return [];
        }

        if ($change->sourceKey === EcommerceShopDbContext::warehouses) {
            return $this->warehouseMutations($change, $productId);
        }
        if ($change->sourceKey === EcommerceShopDbContext::productWarehouseStocks) {
            return $this->stockMutations($change, $productId);
        }

        return [];
    }

    /**
     * Serializes one stock row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Stock row from this table's window or mutation
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
     * The id and the name are columns of warehouse, the units a column of the stock row, none of them
     * personal, so a viewer of the admin view mode sees the whole row.
     *
     * @return array<string, WireField> Row field to where it comes from
     */
    public function wireFields(): array
    {
        return [
            ProductStockTableRow::warehouseId => WireField::column(EcommerceShopDbContext::warehouses, ObjectWarehouse::id),
            ProductStockTableRow::warehouseName => WireField::column(EcommerceShopDbContext::warehouses, ObjectWarehouse::name),
            ProductStockTableRow::units => WireField::column(
                EcommerceShopDbContext::productWarehouseStocks,
                ObjectProductWarehouseStock::units,
            ),
        ];
    }

    /**
     * Answers whether the window of a product holds the row of one warehouse.
     *
     * The whole row is searched the way the window is, so a new warehouse under an active search is
     * announced only when the search would keep it.
     *
     * @param string|int $rowKey Warehouse id
     * @param TableQueryDTO $query Window query whose filter names the product and whose search describes the set
     * @return bool Whether the warehouse is in the product's set
     * @throws DatabaseException When the product, the warehouse or the stock rows cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws HilosException When the kind of the product cannot be read
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): bool
    {
        $row = $this->findRowInWindow($rowKey, $query);

        return $row !== null && $this->containsRowInMemory([$row->toArray()], $rowKey, $this->scopeSearch($query));
    }

    /**
     * Reads an existing warehouse and its current units for the window's stock product.
     *
     * @param string|int $rowKey Warehouse id
     * @param TableQueryDTO $window Window query whose filter names the product
     * @return ?ProductStockTableRow Warehouse stock row, or null when the product or warehouse is absent
     * @throws DatabaseException When the product, the warehouse or the stock rows cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws HilosException When the kind of the product cannot be read
     */
    public function findRowInWindow(string|int $rowKey, TableQueryDTO $window): ?ProductStockTableRow
    {
        $productId = $this->subjectIdOf($window);
        $warehouseId = filter_var($rowKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === null || $warehouseId === false || (string) $warehouseId !== (string) $rowKey) {
            return null;
        }
        if (!isset(Hilos::$db->warehouses[$warehouseId])) {
            return null;
        }

        return $this->rowFor(Hilos::$db->warehouses[$warehouseId], $this->unitsByWarehouse($productId));
    }

    /**
     * Serves the window of one product: a row for every warehouse of the shop.
     * A window that comes without an order goes by the warehouse name.
     *
     * @param TableQueryDTO $query Window query whose filter names the product
     * @return TableSnapshotDTO Stock window, empty when the window names no stock item that exists
     * @throws DatabaseException When the product, the warehouses or the stock rows cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws HilosException When the kind of the product cannot be read
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $productId = $this->subjectIdOf($query);
        if ($productId === null) {
            return $this->filterInMemory([], $query);
        }

        $units = $this->unitsByWarehouse($productId);
        $result = Hilos::$db->warehouses->queryPageItems(new TableQueryDTO());

        return $this->filterInMemory(
            array_map(
                fn (Warehouse $warehouse): array => $this->rowFor($warehouse, $units)->toArray(),
                $result[TableConstants::RESULT_KEY_ROWS],
            ),
            $query->sort === null ? $this->inDefaultOrder($query) : $query,
        );
    }

    /**
     * @return array<string, string> The warehouse name, ordered by itself
     */
    protected function sortableFields(): array
    {
        return [
            ProductStockTableRow::warehouseName => ProductStockTableRow::warehouseName,
        ];
    }

    /**
     * @return array<string, string> The warehouse name, searched as a substring in memory
     */
    protected function searchableFields(): array
    {
        return [
            ProductStockTableRow::warehouseName => ProductStockTableRow::warehouseName,
        ];
    }

    /**
     * Configures the row shape used by the product stock table.
     */
    protected function init(): void
    {
        $this->setRowClass(ProductStockTableRow::class);
    }

    /**
     * The same window in the table's own order, for a window that came without one or whose order was refused.
     *
     * @param TableQueryDTO $query Window query with no order
     * @return TableQueryDTO The window ordered by warehouse name ascending
     */
    private function inDefaultOrder(TableQueryDTO $query): TableQueryDTO
    {
        return new TableQueryDTO(
            search: $query->search,
            sort: $this->defaultSort(),
            limit: $query->limit,
            filter: $query->filter,
            anchor: $query->anchor,
            anchorDirection: $query->anchorDirection,
            pageIndex: $query->pageIndex,
            searchableFields: $query->searchableFields,
            shownFields: $query->shownFields,
        );
    }

    /**
     * Finds the product the window is opened on, when it is a stock item.
     *
     * @param TableQueryDTO $query Window query
     * @return ?int Id of the product, or null when the window names no existing stock item
     * @throws DatabaseException When the product cannot be read
     * @throws LogicException When the products collection is not configured
     * @throws InvalidArgumentException When a loaded product has the wrong type
     * @throws HilosException When the kind of the product cannot be read
     */
    private function subjectIdOf(TableQueryDTO $query): ?int
    {
        $productId = $query->filter[self::FILTER_PRODUCT] ?? null;
        if (!is_int($productId) || $productId <= 0 || !isset(Hilos::$db->products[$productId])) {
            return null;
        }

        return Hilos::$db->products[$productId]->kind === ProductKind::STOCK ? $productId : null;
    }

    /**
     * Builds the mutation a change of one warehouse makes in the window of a product.
     *
     * @param SourceChange $change Change of the warehouses collection
     * @param int $productId Id of the window's product
     * @return list<TableRowMutationDTO> The warehouse's row created, updated or removed, or nothing when it is gone
     * @throws DatabaseException When the warehouse or the stock rows cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    private function warehouseMutations(SourceChange $change, int $productId): array
    {
        if ($change->mutationType === TableMutationType::Delete) {
            return [$this->mutation(TableMutationType::Delete, (int) $change->sourceId)];
        }
        if (!isset(Hilos::$db->warehouses[(int) $change->sourceId])) {
            return [];
        }
        $warehouse = Hilos::$db->warehouses[(int) $change->sourceId];

        return [
            $this->mutation(
                $change->mutationType,
                $warehouse->id,
                $this->rowFor($warehouse, $this->unitsByWarehouse($productId)),
            ),
        ];
    }

    /**
     * Updates the row of every warehouse a stock row of the window's product names.
     *
     * @param SourceChange $change Change of the stock rows collection
     * @param int $productId Id of the window's product
     * @return list<TableRowMutationDTO> Current warehouse rows, without duplicates or vanished warehouses
     * @throws DatabaseException When a warehouse or a stock row cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    private function stockMutations(SourceChange $change, int $productId): array
    {
        $stock = $change->mutationType === TableMutationType::Delete
            ? null
            : Hilos::$db->productWarehouseStocks[(int) $change->sourceId] ?? null;
        $ofProduct = false;
        $warehouseIds = [];
        foreach ([$stock?->toArray() ?? [], $change->row, $change->previous] as $row) {
            $ofProduct = $ofProduct || self::idIn(
                $row,
                ObjectProductWarehouseStock::productId,
                EntityProductWarehouseStock::product_id,
            ) === $productId;
            $warehouseId = self::idIn(
                $row,
                ObjectProductWarehouseStock::warehouseId,
                EntityProductWarehouseStock::warehouse_id,
            );
            if ($warehouseId !== null) {
                $warehouseIds[$warehouseId] = true;
            }
        }
        if (!$ofProduct) {
            return [];
        }

        $units = $this->unitsByWarehouse($productId);
        $mutations = [];
        foreach (array_keys($warehouseIds) as $warehouseId) {
            if (isset(Hilos::$db->warehouses[$warehouseId])) {
                $warehouse = Hilos::$db->warehouses[$warehouseId];
                $mutations[] = $this->mutation(TableMutationType::Update, $warehouse->id, $this->rowFor($warehouse, $units));
            }
        }

        return $mutations;
    }

    /**
     * Reads the units of one product in every warehouse that keeps it.
     *
     * @param int $productId Id of the product
     * @return array<int, int> Units by warehouse id; a warehouse that keeps none of the product is absent
     * @throws DatabaseException When the stock rows cannot be read
     * @throws LogicException When the stock collection is not configured
     * @throws InvalidArgumentException When a loaded stock row has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    private function unitsByWarehouse(int $productId): array
    {
        $units = [];
        foreach (Hilos::$db->productWarehouseStocks->whereColumnIs(ObjectProductWarehouseStock::productId, $productId) as $stock) {
            $units[$stock->warehouseId] = $stock->units;
        }

        return $units;
    }

    /**
     * Builds the row of one warehouse in the window of a product.
     *
     * @param Warehouse $warehouse The row's warehouse
     * @param array<int, int> $units Units of the product by warehouse id
     * @return ProductStockTableRow The row, zero units where the warehouse keeps none of the product
     */
    private function rowFor(Warehouse $warehouse, array $units): ProductStockTableRow
    {
        return new ProductStockTableRow(
            warehouseId: $warehouse->id,
            warehouseName: $warehouse->name,
            units: $units[$warehouse->id] ?? 0,
        );
    }

    /**
     * Reads an id out of a stock row, keyed by object field or by entity column.
     *
     * @param array<string, mixed> $row Stock row as the source or the collection carries it
     * @param string $objectField Object field holding the id
     * @param string $entityColumn Entity column holding the id
     * @return ?int The id, or null when the row does not carry it
     */
    private static function idIn(array $row, string $objectField, string $entityColumn): ?int
    {
        $id = $row[$objectField] ?? $row[$entityColumn] ?? null;
        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }

        return is_int($id) ? $id : null;
    }
}

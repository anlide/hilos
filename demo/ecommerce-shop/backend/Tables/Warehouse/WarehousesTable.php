<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Warehouse;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Demo\EcommerceShop\Database\View\Item\Warehouse;
use Demo\EcommerceShop\Hilos;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\MultiRowViewportTable;
use Hilos\Core\Table\Definition\TableDefinition;
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

/**
 * The shop's warehouses, each with how many products it keeps and how many units of them.
 *
 * The warehouses are a few rows, so the table reads them whole and searches and orders them in
 * memory. The counts are recounted from the stock rows whenever a stock row of a warehouse is
 * written; who may open the table is decided by the page that binds it.
 */
final class WarehousesTable extends TableDefinition implements MultiRowViewportTable
{
    /** Wire slot the row payload rides under. */
    public const string ROW_SLOT = 'warehouse';

    /**
     * The second row only watches: it makes a page process interested in the stock rows, so
     * that their facts reach the window and recount the warehouse they name.
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
                    ObjectWarehouse::id => WarehouseTableRow::id,
                    ObjectWarehouse::name => WarehouseTableRow::name,
                    ObjectWarehouse::address => WarehouseTableRow::address,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    WarehouseTableRow::productCount,
                    WarehouseTableRow::unitCount,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_PRODUCT_WAREHOUSE_STOCKS,
                BrowserTableFieldKey::ROW_KEY => ObjectProductWarehouseStock::warehouseId,
                BrowserTableFieldKey::FIELDS => [
                    ObjectProductWarehouseStock::id,
                    ObjectProductWarehouseStock::productId,
                    ObjectProductWarehouseStock::warehouseId,
                    ObjectProductWarehouseStock::units,
                ],
            ],
        ],
    ];

    /**
     * @return ?TableSortOrderDTO First window ordered by warehouse name ascending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(WarehouseTableRow::name));
    }

    /**
     * A change of the warehouses collection redraws that warehouse; anything else is ignored.
     *
     * A deletion names the removed row's id. A create or an update is read back by the row's
     * id, and a row that is already gone is ignored.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Warehouse row mutation, or null when the change does not affect this table
     * @throws DatabaseException When a warehouse or its stock rows cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== EcommerceShopDbContext::warehouses) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, (int) $change->sourceId);
        }

        if (!isset(Hilos::$db->warehouses[(int) $change->sourceId])) {
            return null;
        }
        $warehouse = Hilos::$db->warehouses[(int) $change->sourceId];

        return $this->mutation($change->mutationType, $warehouse->id, $this->rowFromWarehouse($warehouse));
    }

    /**
     * A stock row redraws every warehouse it names, in either row vocabulary.
     *
     * The current row, the fact's row and its previous row are all read: a deleted row names
     * its warehouse only in the fact, and a moved unique piece names the warehouse it left only
     * in the previous row.
     *
     * @param SourceChange $change Source fact from the warehouses or their stock rows
     * @return list<TableRowMutationDTO> Current warehouse rows, without duplicates or vanished warehouses
     * @throws DatabaseException When a warehouse or a stock row cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    public function buildMutationsForSourceEvent(SourceChange $change): array
    {
        if ($change->kind !== SourceChange::KIND_DB) {
            return [];
        }
        if ($change->sourceKey === EcommerceShopDbContext::warehouses) {
            $mutation = $this->buildMutationForSourceEvent($change);

            return $mutation === null ? [] : [$mutation];
        }
        if ($change->sourceKey !== EcommerceShopDbContext::productWarehouseStocks) {
            return [];
        }

        $stock = $change->mutationType === TableMutationType::Delete
            ? null
            : Hilos::$db->productWarehouseStocks[(int) $change->sourceId] ?? null;
        $warehouseIds = [];
        foreach ([$stock?->toArray() ?? [], $change->row, $change->previous] as $row) {
            $id = $row[ObjectProductWarehouseStock::warehouseId] ?? $row[EntityProductWarehouseStock::warehouse_id] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $warehouseIds[(int) $id] = true;
            }
        }

        $mutations = [];
        foreach (array_keys($warehouseIds) as $warehouseId) {
            if (isset(Hilos::$db->warehouses[$warehouseId])) {
                $warehouse = Hilos::$db->warehouses[$warehouseId];
                $mutations[] = $this->mutation(
                    TableMutationType::Update,
                    $warehouse->id,
                    $this->rowFromWarehouse($warehouse),
                );
            }
        }

        return $mutations;
    }

    /**
     * @param AbstractTableRow $row Warehouse row from this table's window or mutation
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
     * The three stored fields are columns of warehouse, none of them personal.
     * The two counts are computed, nonpersonal facts.
     *
     * @return array<string, WireField> Row field to where it comes from
     */
    public function wireFields(): array
    {
        return [
            WarehouseTableRow::id => WireField::column(EcommerceShopDbContext::warehouses, ObjectWarehouse::id),
            WarehouseTableRow::name => WireField::column(EcommerceShopDbContext::warehouses, ObjectWarehouse::name),
            WarehouseTableRow::address => WireField::column(EcommerceShopDbContext::warehouses, ObjectWarehouse::address),
            WarehouseTableRow::productCount => WireField::notPersonal(),
            WarehouseTableRow::unitCount => WireField::notPersonal(),
        ];
    }

    /**
     * Whether the warehouse is in the set this window would show.
     *
     * Every warehouse is searched the same way the window is, so a new warehouse under an
     * active search is announced only when the search would keep it.
     *
     * @param string|int $rowKey Warehouse id
     * @param TableQueryDTO $query Window query whose search describes the set
     * @return ?bool Whether the warehouse is in the set; this table always knows
     * @throws DatabaseException When the warehouses or their stock rows cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->rows(), $rowKey, $this->scopeSearch($query));
    }

    /**
     * @param Warehouse $warehouse Warehouse to project
     * @return WarehouseTableRow The warehouse with the totals of its stock rows
     * @throws DatabaseException When the stock rows cannot be read
     * @throws LogicException When the stock collection is not configured
     * @throws InvalidArgumentException When a loaded stock row has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     */
    public function rowFromWarehouse(Warehouse $warehouse): WarehouseTableRow
    {
        $totals = Hilos::$db->productWarehouseStocks->totalsInWarehouse($warehouse->id);

        return new WarehouseTableRow(
            id: $warehouse->id,
            name: $warehouse->name,
            address: $warehouse->address,
            productCount: $totals->productCount,
            unitCount: $totals->unitCount,
        );
    }

    /**
     * Every warehouse, searched and ordered in memory.
     *
     * @param TableQueryDTO $query Window query, its search already scoped
     * @return TableSnapshotDTO Warehouses window
     * @throws DatabaseException When the warehouses or their stock rows cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->rows(), $query);
    }

    /**
     * @return array<string, string> The name, ordered by itself
     */
    protected function sortableFields(): array
    {
        return [
            WarehouseTableRow::name => WarehouseTableRow::name,
        ];
    }

    /**
     * @return array<string, string> The name, searched as a substring in memory
     */
    protected function searchableFields(): array
    {
        return [
            WarehouseTableRow::name => WarehouseTableRow::name,
        ];
    }

    /**
     * Configures the row shape used by the warehouses table.
     */
    protected function init(): void
    {
        $this->setRowClass(WarehouseTableRow::class);
    }

    /**
     * Every warehouse as a row payload, read from the database rather than from what this process holds.
     *
     * @return list<array<string, mixed>> Warehouse row payloads
     * @throws DatabaseException When the warehouses or their stock rows cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws ObjectGetIdStringNotImplementedException If a stock row's Object does not implement getIdString()
     * @throws CollectionNotManualException When a stock row is rejected by a non-manual collection
     * @throws TableSearchNotSupportedException When a term arrives with no searchable fields declared
     * @throws TableSearchFieldUnknownException When a declared field names no column of the entity
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    private function rows(): array
    {
        $result = Hilos::$db->warehouses->queryPageItems(new TableQueryDTO());

        return array_map(
            fn (Warehouse $warehouse): array => $this->rowFromWarehouse($warehouse)->toArray(),
            $result[TableConstants::RESULT_KEY_ROWS],
        );
    }
}

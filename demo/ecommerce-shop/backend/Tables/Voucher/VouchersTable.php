<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables\Voucher;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserSource;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Voucher as EntityVoucher;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Hilos;
use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
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

/**
 * The shop's voucher catalog, including codes that are switched off.
 *
 * The catalog is a few rows, so the table reads it whole through the vouchers collection and
 * searches, orders and narrows it in memory. One voucher is one row; switching a code off updates
 * that row rather than removing it. Who may open the table is decided by the page that binds it.
 */
final class VouchersTable extends TableDefinition implements ViewportTable
{
    /** Wire slot the row payload rides under. */
    public const string ROW_SLOT = 'voucher';

    /** Filter key of the Active facet. Absent, null or an empty string means any voucher. */
    public const string FILTER_ACTIVE = VoucherTableRow::active;

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            EcommerceShopBrowserSource::DB_VOUCHERS,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => EcommerceShopBrowserSource::DB_VOUCHERS,
                BrowserTableFieldKey::ROW_KEY => ObjectVoucher::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectVoucher::id => VoucherTableRow::id,
                    ObjectVoucher::code => VoucherTableRow::code,
                    ObjectVoucher::discountPercent => VoucherTableRow::discountPercent,
                    ObjectVoucher::active => VoucherTableRow::active,
                ],
            ],
        ],
    ];

    /** The one filter this table counts, the rows being in memory already. */
    private const array FACETED_FILTERS = [self::FILTER_ACTIVE];

    /**
     * @return ?TableSortOrderDTO First window ordered by code ascending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(VoucherTableRow::code));
    }

    /**
     * A create or an update of the vouchers collection redraws that voucher.
     *
     * The row is read back from the catalog, including fields the fact did not change. A voucher
     * that is already gone, a deletion, any other mutation type and any other source are ignored.
     * Switching a code off is an update of the same row.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Voucher row mutation, or null when the change does not affect this table
     * @throws DatabaseException When the voucher cannot be read
     * @throws LogicException When the vouchers collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== EcommerceShopDbContext::vouchers) {
            return null;
        }
        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, (int) $change->sourceId);
        }
        if (
            $change->mutationType !== TableMutationType::Create
            && $change->mutationType !== TableMutationType::Update
        ) {
            return null;
        }
        if (!isset(Hilos::$db->vouchers[(int) $change->sourceId])) {
            return null;
        }
        $voucher = Hilos::$db->vouchers[(int) $change->sourceId];

        return $this->mutation($change->mutationType, $voucher->id, $this->rowFromVoucher($voucher));
    }

    /**
     * @param AbstractTableRow $row Voucher row from this table's window or mutation
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
     * The four stored fields are columns of voucher, none of them personal.
     *
     * @return array<string, WireField> Row field to where it comes from
     */
    public function wireFields(): array
    {
        return [
            VoucherTableRow::id => WireField::column(EcommerceShopDbContext::vouchers, EntityVoucher::id),
            VoucherTableRow::code => WireField::column(EcommerceShopDbContext::vouchers, EntityVoucher::code),
            VoucherTableRow::discountPercent => WireField::column(
                EcommerceShopDbContext::vouchers,
                EntityVoucher::discount_percent,
            ),
            VoucherTableRow::active => WireField::column(EcommerceShopDbContext::vouchers, EntityVoucher::active),
        ];
    }

    /**
     * Counts the Active options against the vouchers the current search leaves.
     *
     * Each option is counted with the search kept and only the Active value replaced, and the count
     * is exact: the rows are in hand.
     *
     * @param TableQueryDTO $query Window query whose search and filters describe the set, its search scoped
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts of the
     *     Active options that were asked about
     * @throws DatabaseException When the vouchers cannot be read
     * @throws LogicException When the vouchers collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        $rows = $this->rows();

        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, array_flip(self::FACETED_FILTERS)),
            fn (TableQueryDTO $set): TableFacetCountDTO => new TableFacetCountDTO(
                $this->filterInMemory($this->narrow($rows, $set), $set)->totalCount,
                true,
            ),
        );
    }

    /**
     * Whether the voucher is in the set this window would show.
     *
     * The one voucher is narrowed by the Active filter and searched the same way the window is, so
     * a new or edited voucher is announced only when the window would keep it.
     *
     * @param string|int $rowKey Voucher id
     * @param TableQueryDTO $query Window query whose search and filters describe the set
     * @return ?bool Whether the voucher is in the set; this table always knows
     * @throws DatabaseException When the voucher cannot be read
     * @throws LogicException When the vouchers collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        if (!isset(Hilos::$db->vouchers[(int) $rowKey])) {
            return false;
        }
        $voucher = Hilos::$db->vouchers[(int) $rowKey];

        return $this->containsRowInMemory(
            $this->narrow([$this->rowFromVoucher($voucher)->toArray()], $query),
            $voucher->id,
            $this->scopeSearch($query),
        );
    }

    /**
     * @param Voucher $voucher Voucher to project
     * @return VoucherTableRow The voucher as this table shows it
     */
    public function rowFromVoucher(Voucher $voucher): VoucherTableRow
    {
        return new VoucherTableRow(
            id: $voucher->id,
            code: $voucher->code,
            discountPercent: $voucher->discountPercent,
            active: $voucher->active,
        );
    }

    /**
     * Every voucher, narrowed by Active, then searched and ordered in memory.
     *
     * @param TableQueryDTO $query Window query, its search already scoped
     * @return TableSnapshotDTO Vouchers window
     * @throws DatabaseException When the vouchers cannot be read
     * @throws LogicException When the vouchers collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->narrow($this->rows(), $query), $query);
    }

    /**
     * @return array<string, string> The code, ordered by itself
     */
    protected function sortableFields(): array
    {
        return [
            VoucherTableRow::code => VoucherTableRow::code,
        ];
    }

    /**
     * @return array<string, string> The code, searched as a substring in memory
     */
    protected function searchableFields(): array
    {
        return [
            VoucherTableRow::code => VoucherTableRow::code,
        ];
    }

    /**
     * Configures the row shape used by the vouchers table.
     */
    protected function init(): void
    {
        $this->setRowClass(VoucherTableRow::class);
    }

    /**
     * Every voucher as a row payload, read from the database rather than from what this process holds.
     *
     * @return list<array<string, mixed>> Voucher row payloads
     * @throws DatabaseException When the vouchers cannot be read
     * @throws LogicException When the vouchers collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws TableSearchNotSupportedException When a term arrives with no searchable fields declared
     * @throws TableSearchFieldUnknownException When a declared field names no column of the entity
     * @throws ValidationException When a collection-specific window filter is malformed
     */
    private function rows(): array
    {
        $result = Hilos::$db->vouchers->queryPageItems(new TableQueryDTO());

        return array_map(
            fn (Voucher $voucher): array => $this->rowFromVoucher($voucher)->toArray(),
            $result[TableConstants::RESULT_KEY_ROWS],
        );
    }

    /**
     * Applies the Active filter of the window.
     *
     * A filter that is absent, null or empty narrows nothing. True keeps the active vouchers and
     * false keeps the ones that are off. Any other value, including the strings "true" and "false"
     * and any number, names no voucher, and the set is empty rather than refused.
     *
     * @param list<array<string, mixed>> $rows Voucher row payloads
     * @param TableQueryDTO $query Window query
     * @return list<array<string, mixed>> Rows the window asked for
     */
    private function narrow(array $rows, TableQueryDTO $query): array
    {
        $value = $query->filter[self::FILTER_ACTIVE] ?? null;
        if ($value === null || $value === '') {
            return $rows;
        }
        if ($value !== true && $value !== false) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row[VoucherTableRow::active] === $value,
        ));
    }
}

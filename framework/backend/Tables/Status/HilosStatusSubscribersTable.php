<?php

declare(strict_types=1);

namespace Hilos\Tables\Status;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\StatusSubscriber as ObjectStatusSubscriber;
use Hilos\Database\View\Item\StatusSubscriber;
use Hilos\Hilos;
use Hilos\HilosException;

/** DB-backed subscriber window; ordinary mutations keep the standard Apply and own-action rules. */
final class HilosStatusSubscribersTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosStatusSubscribers';
    public const string SLOT_SUBSCRIBER = 'subscriber';
    public const string FILTER_CONFIRMED = HilosStatusSubscribersTableRow::confirmed;

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusSubscribers],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusSubscribers,
                ],
                BrowserTableFieldKey::ROW_KEY => StatusSubscriber::id,
                BrowserTableFieldKey::FIELDS => [
                    StatusSubscriber::id => HilosStatusSubscribersTableRow::id,
                    StatusSubscriber::email => HilosStatusSubscribersTableRow::email,
                    StatusSubscriber::subscribedAt => HilosStatusSubscribersTableRow::subscribedAt,
                    StatusSubscriber::confirmed => HilosStatusSubscribersTableRow::confirmed,
                ],
            ],
        ],
    ];

    /** @return TableSortOrderDTO Newest subscriptions first, settled by id in the same direction */
    public function defaultSort(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosStatusSubscribersTableRow::subscribedAt, TableConstants::ORDER_DESC));
    }

    /** @return array<string, WireField> All four fields retain their Entity personal-data verdicts */
    public function wireFields(): array
    {
        return [
            HilosStatusSubscribersTableRow::id => WireField::column(HilosDbContext::statusSubscribers, ObjectStatusSubscriber::id),
            HilosStatusSubscribersTableRow::email => WireField::column(HilosDbContext::statusSubscribers, ObjectStatusSubscriber::email),
            HilosStatusSubscribersTableRow::subscribedAt => WireField::column(
                HilosDbContext::statusSubscribers, ObjectStatusSubscriber::subscribed_at,
            ),
            HilosStatusSubscribersTableRow::confirmed => WireField::column(HilosDbContext::statusSubscribers, ObjectStatusSubscriber::confirmed),
        ];
    }

    /**
     * @param SourceChange $change Committed DB mutation
     * @return ?TableRowMutationDTO Current minimal row, deletion or clear
     * @throws HilosException When the changed row cannot be read
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if (!$change->isDb() || $change->sourceKey !== HilosDbContext::statusSubscribers) {
            return null;
        }
        if ($change->mutationType === TableMutationType::Clear) {
            return $this->mutation(TableMutationType::Clear, self::TABLE);
        }
        $id = (int) $change->sourceId;
        if ($change->mutationType === TableMutationType::Delete || !isset(Hilos::$db->statusSubscribers[$id])) {
            return $this->mutation(TableMutationType::Delete, $id);
        }
        return $this->mutation($change->mutationType, $id,
            HilosStatusSubscribersTableRow::fromSubscriber(Hilos::$db->statusSubscribers[$id]));
    }

    /**
     * @param SourceChange $change Committed source mutation
     * @return int|string|null Affected subscriber, or null when unrelated or cleared
     */
    public function rowKeyForSourceEvent(SourceChange $change): int|string|null
    {
        return $change->isDb() && $change->sourceKey === HilosDbContext::statusSubscribers
            && $change->mutationType !== TableMutationType::Clear ? (int) $change->sourceId : null;
    }

    /**
     * @param AbstractTableRow $row Minimal subscriber row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Standard browser slot envelope
     * @throws TableRowKeyMissingException When the row has no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::SLOT_SUBSCRIBER => $row->toArray()]];
    }

    /**
     * @param AbstractTableRow $row Subscriber row to place
     * @param TableAnchorDTO $anchor Boundary in DB column names
     * @param TableQueryDTO $query Current window order
     * @return ?int Relative place in the ordinary viewport comparator
     */
    public function placeRowAgainst(AbstractTableRow $row, TableAnchorDTO $anchor, TableQueryDTO $query): ?int
    {
        $values = [];
        foreach ($this->sortableFields() + [HilosStatusSubscribersTableRow::id => ObjectStatusSubscriber::id] as $field => $column) {
            if (array_key_exists($column, $anchor->values)) {
                $values[$field] = $anchor->values[$column];
            }
        }
        return parent::placeRowAgainst($row, new TableAnchorDTO($values), $query);
    }

    /**
     * @param AbstractTableRow $row Subscriber row to address
     * @param TableQueryDTO $query Current window order
     * @return ?TableAnchorDTO Place in the same DB column names as queryPage
     */
    public function anchorForRow(AbstractTableRow $row, TableQueryDTO $query): ?TableAnchorDTO
    {
        $anchor = parent::anchorForRow($row, $query);
        if ($anchor === null) {
            return null;
        }
        $columns = $this->sortableFields() + [HilosStatusSubscribersTableRow::id => ObjectStatusSubscriber::id];
        $values = [];
        foreach ($anchor->values as $field => $value) {
            $values[$columns[$field]] = $value;
        }
        return new TableAnchorDTO($values);
    }

    /**
     * @param string|int $rowKey Subscriber primary id
     * @param TableQueryDTO $query Filtered and searched set
     * @return ?bool Whether the subscriber belongs to the set
     * @throws HilosException When membership or search cannot be read
     * @throws TableSearchNotSupportedException When search cannot be scoped
     * @throws TableSearchFieldUnknownException When a search field is not a column
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInDbCollection(Hilos::$db->statusSubscribers, $rowKey, $this->scopeSearch($query));
    }

    /**
     * @param string|int $rowKey Persisted subscriber primary id
     * @return ?AbstractTableRow Current row, including one outside the visible window
     * @throws HilosException When the primary-key read fails
     */
    public function findRow(string|int $rowKey): ?AbstractTableRow
    {
        return isset(Hilos::$db->statusSubscribers[$rowKey])
            ? HilosStatusSubscribersTableRow::fromSubscriber(Hilos::$db->statusSubscribers[$rowKey]) : null;
    }

    /**
     * @param TableQueryDTO $query Current set, including the viewer's search scope
     * @param array<string, list<int|float|string|bool>> $wanted Filter choices requested by the window
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Confirmed facet alone
     * @throws HilosException When counting or search fails
     * @throws TableSearchNotSupportedException When search cannot be scoped
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): array
    {
        return TableFacetTally::forFilters($this->scopeSearch($query),
            array_intersect_key($wanted, [self::FILTER_CONFIRMED => true]),
            function (TableQueryDTO $set): TableFacetCountDTO {
                $snapshot = $this->getPage($set->withLimit(1));
                return new TableFacetCountDTO($snapshot->totalCount, $snapshot->totalExact);
            });
    }

    /** Configures only the minimal row; writes belong to the Status owner. */
    protected function init(): void
    {
        $this->setRowClass(HilosStatusSubscribersTableRow::class);
    }

    /** @return array<string, string> Indexed scalar orders; the ORM appends the primary id */
    protected function sortableFields(): array
    {
        return [HilosStatusSubscribersTableRow::email => ObjectStatusSubscriber::email,
            HilosStatusSubscribersTableRow::subscribedAt => ObjectStatusSubscriber::subscribed_at,
            HilosStatusSubscribersTableRow::confirmed => ObjectStatusSubscriber::confirmed];
    }

    /** @return array<string, string> Only email is searched, subject to the viewer scope */
    protected function searchableFields(): array
    {
        return [HilosStatusSubscribersTableRow::email => ObjectStatusSubscriber::email];
    }

    /**
     * Uses bounded typed View reads because generic frontend serialization carries delivery-only fields.
     * The collection resolves the confirmed predicate once for windows, facets and contains.
     *
     * @param TableQueryDTO $query Scoped window query
     * @return TableSnapshotDTO DB window with exactly the subscriber browser fields
     * @throws HilosException When querying or projecting the window fails
     * @throws TableSearchNotSupportedException When search cannot be scoped
     * @throws TableSearchFieldUnknownException When a search field is not a column
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $query = $query->limit <= TableConstants::NO_LIMIT ? $query->withLimit($this->windowSize()) : $query;
        $window = Hilos::$db->statusSubscribers->queryPageItems($query);
        return new TableSnapshotDTO(
            rows: array_map(HilosStatusSubscribersTableRow::fromSubscriber(...), $window[TableConstants::RESULT_KEY_ROWS]),
            totalCount: $window[TableConstants::RESULT_KEY_TOTAL_COUNT],
            totalExact: $window[TableConstants::RESULT_KEY_TOTAL_EXACT],
            limit: $query->limit,
            firstAnchor: $window[TableConstants::RESULT_KEY_FIRST_ANCHOR],
            lastAnchor: $window[TableConstants::RESULT_KEY_LAST_ANCHOR],
            rowsBefore: $window[TableConstants::RESULT_KEY_ROWS_BEFORE],
            frame: $window[TableConstants::RESULT_KEY_FRAME],
        );
    }
}

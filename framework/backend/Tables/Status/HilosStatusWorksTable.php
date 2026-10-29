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
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Database\View\Collection\StatusWorks;
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
use Hilos\Database\Object\Item\StatusWork as ObjectStatusWork;
use Hilos\Database\View\Item\StatusWork;
use Hilos\Database\Entity\Item\StatusWorkComponent;
use Hilos\Hilos;
use Hilos\HilosException;

/** DB-backed work window; ordinary mutations keep the standard Apply and own-action rules. */
final class HilosStatusWorksTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosStatusWorks';
    public const string SLOT_WORK = 'work';
    public const string FILTER_COMPONENT_KEY = 'componentKey';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusWorks],
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusWorkComponents],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusWorks,
                ],
                BrowserTableFieldKey::ROW_KEY => StatusWork::id,
                BrowserTableFieldKey::FIELDS => [
                    StatusWork::id => HilosStatusWorksTableRow::id,
                    StatusWork::publicId => HilosStatusWorksTableRow::publicId,
                    StatusWork::title => HilosStatusWorksTableRow::title,
                    StatusWork::description => HilosStatusWorksTableRow::description,
                    StatusWork::startsAt => HilosStatusWorksTableRow::startsAt,
                    StatusWork::endsAt => HilosStatusWorksTableRow::endsAt,
                    StatusWork::version => HilosStatusWorksTableRow::version,
                    StatusWork::canceledAt => HilosStatusWorksTableRow::canceledAt,
                    StatusWork::consumedAt => HilosStatusWorksTableRow::consumedAt,
                    StatusWork::expiredAt => HilosStatusWorksTableRow::expiredAt,
                    StatusWork::lastMailQueuedAt => HilosStatusWorksTableRow::lastMailQueuedAt,
                ],
            ],
        ],
    ];

    /** @return int At most one bounded storage portion per window */
    public function windowSize(): int
    {
        return StatusWorks::MAX_PORTION_ROWS;
    }

    /** @return TableSortOrderDTO Nearest scheduled start first, settled by id in the same direction */
    public function defaultSort(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosStatusWorksTableRow::startsAt, TableConstants::ORDER_ASC));
    }

    /** @return array<string, WireField> Native columns retain their verdict; component keys come from the bridge */
    public function wireFields(): array
    {
        return [
            HilosStatusWorksTableRow::id => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::id),
            HilosStatusWorksTableRow::publicId => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::public_id),
            HilosStatusWorksTableRow::title => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::title),
            HilosStatusWorksTableRow::description => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::description),
            HilosStatusWorksTableRow::startsAt => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::starts_at),
            HilosStatusWorksTableRow::endsAt => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::ends_at),
            HilosStatusWorksTableRow::version => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::version),
            HilosStatusWorksTableRow::canceledAt => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::canceled_at),
            HilosStatusWorksTableRow::consumedAt => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::consumed_at),
            HilosStatusWorksTableRow::expiredAt => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::expired_at),
            HilosStatusWorksTableRow::lastMailQueuedAt => WireField::column(HilosDbContext::statusWorks, ObjectStatusWork::last_mail_queued_at),
            HilosStatusWorksTableRow::componentKeys => WireField::column(
                HilosDbContext::statusWorkComponents, StatusWorkComponent::component_key,
            ),
        ];
    }

    /**
     * @param SourceChange $change Committed DB mutation
     * @return ?TableRowMutationDTO Current minimal row, deletion or clear
     * @throws HilosException When the changed row cannot be read
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if (!$change->isDb() || !in_array($change->sourceKey, [
            HilosDbContext::statusWorks, HilosDbContext::statusWorkComponents,
        ], true)) {
            return null;
        }
        if ($change->mutationType === TableMutationType::Clear) {
            if ($change->sourceKey === HilosDbContext::statusWorkComponents) {
                throw new PageInternalErrorException('The component collection was cleared; the work window must be rebuilt.');
            }
            return $this->mutation(TableMutationType::Clear, self::TABLE);
        }
        $id = $this->rowKeyForSourceEvent($change);
        if ($id === null) {
            throw new PageInternalErrorException('The changed component has no maintenance work.');
        }
        if (!isset(Hilos::$db->statusWorks[$id])) {
            return $this->mutation(TableMutationType::Delete, $id);
        }
        return $this->mutation($change->sourceKey === HilosDbContext::statusWorkComponents ? TableMutationType::Update : $change->mutationType,
            $id, HilosStatusWorksTableRow::fromWork(Hilos::$db->statusWorks[$id]));
    }

    /**
     * @param SourceChange $change Committed work or component fact, including the deleted bridge row
     * @return int|string|null Parent work primary id; a component id is never a work id
     * @throws HilosException When a bridge row cannot be read
     */
    public function rowKeyForSourceEvent(SourceChange $change): int|string|null
    {
        if (!$change->isDb() || $change->mutationType === TableMutationType::Clear) {
            return null;
        }
        if ($change->sourceKey === HilosDbContext::statusWorks) {
            return (int) $change->sourceId;
        }
        if ($change->sourceKey !== HilosDbContext::statusWorkComponents) {
            return null;
        }
        if (isset($change->row[StatusWorkComponent::work_id])) {
            return (int) $change->row[StatusWorkComponent::work_id];
        }
        if (isset($change->previous[StatusWorkComponent::work_id])) {
            return (int) $change->previous[StatusWorkComponent::work_id];
        }
        return isset(Hilos::$db->statusWorkComponents[$change->sourceId])
            ? Hilos::$db->statusWorkComponents[$change->sourceId]->workId : null;
    }

    /**
     * @param AbstractTableRow $row Minimal work row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Standard browser slot envelope
     * @throws TableRowKeyMissingException When the row has no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::SLOT_WORK => $row->toArray()]];
    }

    /**
     * @param AbstractTableRow $row Work row to place
     * @param TableAnchorDTO $anchor Boundary in DB column names
     * @param TableQueryDTO $query Current window order
     * @return ?int Relative place in the ordinary viewport comparator
     */
    public function placeRowAgainst(AbstractTableRow $row, TableAnchorDTO $anchor, TableQueryDTO $query): ?int
    {
        $values = [];
        foreach ($this->sortableFields() + [HilosStatusWorksTableRow::id => ObjectStatusWork::id] as $field => $column) {
            if (array_key_exists($column, $anchor->values)) {
                $values[$field] = $anchor->values[$column];
            }
        }
        return parent::placeRowAgainst($row, new TableAnchorDTO($values), $query);
    }

    /**
     * @param AbstractTableRow $row Work row to address
     * @param TableQueryDTO $query Current window order
     * @return ?TableAnchorDTO Place in the same DB column names as queryPage
     */
    public function anchorForRow(AbstractTableRow $row, TableQueryDTO $query): ?TableAnchorDTO
    {
        $anchor = parent::anchorForRow($row, $query);
        if ($anchor === null) {
            return null;
        }
        $columns = $this->sortableFields() + [HilosStatusWorksTableRow::id => ObjectStatusWork::id];
        $values = [];
        foreach ($anchor->values as $field => $value) {
            $values[$columns[$field]] = $value;
        }
        return new TableAnchorDTO($values);
    }

    /**
     * @param string|int $rowKey Work primary id
     * @param TableQueryDTO $query Filtered and searched set
     * @return ?bool Whether the work belongs to the set
     * @throws HilosException When membership or search cannot be read
     * @throws TableSearchNotSupportedException When search cannot be scoped
     * @throws TableSearchFieldUnknownException When a search field is not a column
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInDbCollection(Hilos::$db->statusWorks, $rowKey, $this->scopeWorkQuery($this->scopeSearch($query)));
    }

    /**
     * @param string|int $rowKey Persisted work primary id
     * @return ?AbstractTableRow Current row, including one outside the visible window
     * @throws HilosException When the primary-key read fails
     */
    public function findRow(string|int $rowKey): ?AbstractTableRow
    {
        return isset(Hilos::$db->statusWorks[$rowKey])
            ? HilosStatusWorksTableRow::fromWork(Hilos::$db->statusWorks[$rowKey]) : null;
    }

    /**
     * @param TableQueryDTO $query Current set, including the viewer's search scope
     * @param array<string, list<int|float|string|bool>> $wanted Filter choices requested by the window
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Component facet alone
     * @throws HilosException When counting or search fails
     * @throws TableSearchNotSupportedException When search cannot be scoped
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): array
    {
        return TableFacetTally::forFilters($this->scopeSearch($query),
            array_intersect_key($wanted, [self::FILTER_COMPONENT_KEY => true]),
            function (TableQueryDTO $set): TableFacetCountDTO {
                $snapshot = $this->getPage($set->withLimit(1));
                return new TableFacetCountDTO($snapshot->totalCount, $snapshot->totalExact);
            });
    }

    /** Configures only the minimal row; writes belong to the Status owner. */
    protected function init(): void
    {
        $this->setRowClass(HilosStatusWorksTableRow::class);
    }

    /** @return array<string, string> Indexed scalar orders; the ORM appends the primary id */
    protected function sortableFields(): array
    {
        return [HilosStatusWorksTableRow::startsAt => ObjectStatusWork::starts_at,
            HilosStatusWorksTableRow::endsAt => ObjectStatusWork::ends_at,
            HilosStatusWorksTableRow::title => ObjectStatusWork::title];
    }

    /** @return array<string, string> Public title and description are searched within the viewer scope */
    protected function searchableFields(): array
    {
        return [HilosStatusWorksTableRow::title => ObjectStatusWork::title,
            HilosStatusWorksTableRow::description => ObjectStatusWork::description];
    }

    /**
     * Uses bounded typed View reads because generic scalar serialization carries storage-only fields.
     * The collection resolves the unfinished predicate once for windows, facets and contains.
     *
     * @param TableQueryDTO $query Scoped window query
     * @return TableSnapshotDTO DB window with exactly the work browser fields
     * @throws HilosException When querying or projecting the window fails
     * @throws TableSearchNotSupportedException When search cannot be scoped
     * @throws TableSearchFieldUnknownException When a search field is not a column
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $query = $this->scopeWorkQuery($query);
        $query = $query->withLimit($query->limit <= TableConstants::NO_LIMIT
            ? $this->windowSize() : min($query->limit, $this->windowSize()));
        $window = Hilos::$db->statusWorks->queryPageItems($query);
        return new TableSnapshotDTO(
            rows: array_map(HilosStatusWorksTableRow::fromWork(...), $window[TableConstants::RESULT_KEY_ROWS]),
            totalCount: $window[TableConstants::RESULT_KEY_TOTAL_COUNT],
            totalExact: $window[TableConstants::RESULT_KEY_TOTAL_EXACT],
            limit: $query->limit,
            firstAnchor: $window[TableConstants::RESULT_KEY_FIRST_ANCHOR],
            lastAnchor: $window[TableConstants::RESULT_KEY_LAST_ANCHOR],
            rowsBefore: $window[TableConstants::RESULT_KEY_ROWS_BEFORE],
            frame: $window[TableConstants::RESULT_KEY_FRAME],
        );
    }

    /**
     * @param TableQueryDTO $query Client component facet beside the declared scalar order
     * @return TableQueryDTO Same window with the component facet mapped to its persisted key
     * @throws ValidationException When the filter is not a declared catalog component
     * @throws HilosException When the catalog declaration is invalid
     */
    private function scopeWorkQuery(TableQueryDTO $query): TableQueryDTO
    {
        if (!array_key_exists(self::FILTER_COMPONENT_KEY, $query->filter)) {
            return $query;
        }
        $key = $query->filter[self::FILTER_COMPONENT_KEY];
        if (!is_string($key) || !array_key_exists($key, iterator_to_array(Hilos::statusComponents()))) {
            throw new ValidationException('Choose a status component.');
        }
        return $query->withoutFilter(self::FILTER_COMPONENT_KEY)->withFilter(StatusWorkComponent::component_key, $key);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tables\Status;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\AdminViewMode\WireField;
use Hilos\Auth\Session\BrowserTimeZone;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableWindowFrameDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Core\Table\TableWindowPlan;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\StatusIncident as ObjectStatusIncident;
use Hilos\Database\Object\Item\StatusMaintenancePeriod as ObjectStatusMaintenancePeriod;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Database\View\Item\StatusMaintenancePeriod;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Status\DTO\StatusHistoryAnchor;
use Hilos\Status\DTO\StatusHistoryEntry;
use Hilos\Status\DTO\StatusHistoryQuery;

/** Read-only, bounded merge of completed incident and maintenance snapshots. */
final class HilosStatusHistoryTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosStatusHistory';
    public const string SLOT_HISTORY = 'history';
    public const string FILTER_COMPONENT_KEY = 'componentKey';
    public const string FILTER_OUTCOME = 'outcome';
    public const string FILTER_FROM = 'from';
    public const string FILTER_TO = 'to';
    public const string FILTER_TIME_ZONE = 'timeZone';

    private const int ANCHOR_VALUE_COUNT = 3;

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusIncidents],
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusMaintenancePeriods],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusIncidents,
                ],
                BrowserTableFieldKey::ROW_KEY => StatusIncident::id,
                BrowserTableFieldKey::FIELDS => [
                    StatusIncident::id => HilosStatusHistoryTableRow::sourceId,
                    StatusIncident::detectedAt => HilosStatusHistoryTableRow::startedAt,
                    StatusIncident::resolvedAt => HilosStatusHistoryTableRow::endedAt,
                    StatusIncident::publishedAt => HilosStatusHistoryTableRow::publishedAt,
                    StatusIncident::componentName => HilosStatusHistoryTableRow::title,
                    StatusIncident::componentKey => HilosStatusHistoryTableRow::componentKeys,
                    StatusIncident::severity => HilosStatusHistoryTableRow::severity,
                    StatusIncident::peakSeverity => HilosStatusHistoryTableRow::severity,
                    StatusIncident::outcome => HilosStatusHistoryTableRow::outcome,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusMaintenancePeriods,
                ],
                BrowserTableFieldKey::ROW_KEY => StatusMaintenancePeriod::id,
                BrowserTableFieldKey::FIELDS => [
                    StatusMaintenancePeriod::id => HilosStatusHistoryTableRow::sourceId,
                    StatusMaintenancePeriod::startedAt => HilosStatusHistoryTableRow::startedAt,
                    StatusMaintenancePeriod::endedAt => HilosStatusHistoryTableRow::endedAt,
                    StatusMaintenancePeriod::completionObservedAt => HilosStatusHistoryTableRow::completionObservedAt,
                    StatusMaintenancePeriod::title => HilosStatusHistoryTableRow::title,
                    StatusMaintenancePeriod::componentKeysJson => HilosStatusHistoryTableRow::componentKeys,
                    StatusMaintenancePeriod::announcementKnown => HilosStatusHistoryTableRow::announcementKnown,
                    StatusMaintenancePeriod::workPublicId => HilosStatusHistoryTableRow::workPublicId,
                ],
            ],
        ],
    ];

    /** @return int Maximum merged window size */
    public function windowSize(): int
    {
        return StatusHistoryQuery::MAX_WINDOW_ROWS;
    }

    /** @return TableSortOrderDTO Latest completed fact's start first */
    public function defaultSort(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosStatusHistoryTableRow::startedAt, TableConstants::ORDER_DESC));
    }

    /** @return array<string, WireField> Column origins and derived nonpersonal source labels */
    public function wireFields(): array
    {
        return [
            HilosStatusHistoryTableRow::id => WireField::notPersonal(),
            HilosStatusHistoryTableRow::kind => WireField::notPersonal(),
            HilosStatusHistoryTableRow::sourceId => WireField::column(HilosDbContext::statusIncidents, ObjectStatusIncident::id),
            HilosStatusHistoryTableRow::startedAt => WireField::column(HilosDbContext::statusIncidents, ObjectStatusIncident::detected_at),
            HilosStatusHistoryTableRow::endedAt => WireField::column(HilosDbContext::statusIncidents, ObjectStatusIncident::resolved_at),
            HilosStatusHistoryTableRow::completionObservedAt => WireField::column(
                HilosDbContext::statusMaintenancePeriods, ObjectStatusMaintenancePeriod::completion_observed_at,
            ),
            HilosStatusHistoryTableRow::publishedAt => WireField::column(HilosDbContext::statusIncidents, ObjectStatusIncident::published_at),
            HilosStatusHistoryTableRow::outcome => WireField::notPersonal(),
            HilosStatusHistoryTableRow::title => WireField::column(HilosDbContext::statusIncidents, ObjectStatusIncident::component_name),
            HilosStatusHistoryTableRow::componentKeys => WireField::column(
                HilosDbContext::statusMaintenancePeriods, ObjectStatusMaintenancePeriod::component_keys_json,
            ),
            HilosStatusHistoryTableRow::severity => WireField::notPersonal(),
            HilosStatusHistoryTableRow::announcementKnown => WireField::column(
                HilosDbContext::statusMaintenancePeriods, ObjectStatusMaintenancePeriod::announcement_known,
            ),
            HilosStatusHistoryTableRow::workPublicId => WireField::column(
                HilosDbContext::statusMaintenancePeriods, ObjectStatusMaintenancePeriod::work_public_id,
            ),
        ];
    }

    /**
     * @param SourceChange $change Source fact from one of the two completed-history sources
     * @return ?TableRowMutationDTO Complete current projection or namespaced deletion
     * @throws HilosException When a current source row cannot be read or projected
     * @throws PageInternalErrorException When a source clear requires the complete merged window to be rebuilt
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if (!$change->isDb() || !in_array($change->sourceKey, [
            HilosDbContext::statusIncidents, HilosDbContext::statusMaintenancePeriods,
        ], true)) {
            return null;
        }
        if ($change->mutationType === TableMutationType::Clear) {
            throw new PageInternalErrorException('The history source was cleared; the complete window must be rebuilt.');
        }
        $key = $this->rowKeyForSourceEvent($change);
        if ($key === null) {
            return null;
        }
        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $key);
        }
        $row = $this->findRow($key);
        if ($row === null) {
            return null;
        }
        $completion = $change->sourceKey === HilosDbContext::statusIncidents
            ? ObjectStatusIncident::resolved_at : ObjectStatusMaintenancePeriod::completion_observed_at;
        $arrived = $change->mutationType === TableMutationType::Update
            && array_key_exists($completion, $change->row) && $change->row[$completion] !== null
            && array_key_exists($completion, $change->previous) && $change->previous[$completion] === null;
        return $this->mutation($arrived ? TableMutationType::Create : $change->mutationType, $key, $row);
    }

    /**
     * @param SourceChange $change DB source fact
     * @return ?string Namespaced source primary key, never the other source's equal numeric id
     */
    public function rowKeyForSourceEvent(SourceChange $change): ?string
    {
        if (!$change->isDb() || $change->mutationType === TableMutationType::Clear
            || preg_match('/\A[1-9][0-9]*\z/', $change->sourceId) !== 1
            || (string)(int)$change->sourceId !== $change->sourceId) {
            return null;
        }
        $kind = match ($change->sourceKey) {
            HilosDbContext::statusIncidents => StatusHistoryEntry::INCIDENT,
            HilosDbContext::statusMaintenancePeriods => StatusHistoryEntry::MAINTENANCE,
            default => null,
        };
        return $kind === null ? null : $kind . ':' . $change->sourceId;
    }

    /**
     * @param AbstractTableRow $row Normalized completed fact
     * @return array{rowKey: int|string, sources: array<string, mixed>} Standard history slot envelope
     * @throws TableRowKeyMissingException When the row has no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::SLOT_HISTORY => $row->toArray()]];
    }

    /**
     * @param string|int $rowKey Namespaced source primary key
     * @return ?HilosStatusHistoryTableRow Current completed fact, including one outside a window
     * @throws HilosException When a source row cannot be read or projected
     */
    public function findRow(string|int $rowKey): ?HilosStatusHistoryTableRow
    {
        if (!is_string($rowKey) || preg_match('/\A(incident|maintenance):([1-9][0-9]*)\z/', $rowKey, $parts) !== 1
            || (string)(int)$parts[2] !== $parts[2]) {
            return null;
        }
        $id = (int)$parts[2];
        if ($parts[1] === StatusHistoryEntry::INCIDENT) {
            return isset(Hilos::$db->statusIncidents[$id]) && Hilos::$db->statusIncidents[$id]->resolvedAt !== null
                ? HilosStatusHistoryTableRow::fromIncident(Hilos::$db->statusIncidents[$id]) : null;
        }
        return isset(Hilos::$db->statusMaintenancePeriods[$id])
            && Hilos::$db->statusMaintenancePeriods[$id]->completionObservedAt !== null
                ? HilosStatusHistoryTableRow::fromMaintenance(Hilos::$db->statusMaintenancePeriods[$id]) : null;
    }

    /**
     * @param string|int $rowKey Namespaced source primary key
     * @param TableQueryDTO $query Filtered and searched set, independent of its current window address
     * @return bool Whether the completed row belongs to the same SQL predicate as the window
     * @throws HilosException When a row or its membership cannot be read
     * @throws TableSearchNotSupportedException When search cannot be scoped
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): bool
    {
        $set = self::historyQuery($this->scopeSearch($query));
        $row = $this->findRow($rowKey);
        if ($row === null) {
            return false;
        }
        return $row->kind === StatusHistoryEntry::INCIDENT
            ? Hilos::$db->statusIncidents->historyContains($row->sourceId, $set)
            : Hilos::$db->statusMaintenancePeriods->historyContains($row->sourceId, $set);
    }

    /**
     * @param AbstractTableRow $row Normalized history row
     * @param TableQueryDTO $query Current order
     * @return ?TableAnchorDTO Full numeric tuple, independent of the namespaced string key
     */
    public function anchorForRow(AbstractTableRow $row, TableQueryDTO $query): ?TableAnchorDTO
    {
        return $row instanceof HilosStatusHistoryTableRow ? self::rowAnchor($row) : null;
    }

    /**
     * @param AbstractTableRow $row History row to place
     * @param TableAnchorDTO $anchor Complete numeric boundary
     * @param TableQueryDTO $query Current canonical order
     * @return ?int Negative before, zero at, positive after the anchor; null for a malformed boundary
     */
    public function placeRowAgainst(AbstractTableRow $row, TableAnchorDTO $anchor, TableQueryDTO $query): ?int
    {
        if (!$row instanceof HilosStatusHistoryTableRow) {
            return null;
        }
        try {
            $place = self::readAnchor(self::rowAnchor($row));
            $against = self::readAnchor($anchor);
        } catch (ValidationException) {
            return null;
        }
        return self::compare($place, $against) * (self::order($query) === TableConstants::ORDER_ASC ? 1 : -1);
    }

    /**
     * @param TableQueryDTO $query Scoped set
     * @param array<string, list<int|float|string|bool>> $wanted Filter choices requested by the viewport
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Component and outcome facets
     * @throws HilosException When counting fails or a filter is invalid
     * @throws TableSearchNotSupportedException When search cannot be scoped
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): array
    {
        return TableFacetTally::forFilters($this->scopeSearch($query),
            array_intersect_key($wanted, [self::FILTER_COMPONENT_KEY => true, self::FILTER_OUTCOME => true]),
            static fn(TableQueryDTO $set): TableFacetCountDTO => self::countSet(self::historyQuery($set)));
    }

    /** Configures only the read-only row; there are no table or item mutation actions. */
    protected function init(): void
    {
        $this->setRowClass(HilosStatusHistoryTableRow::class);
    }

    /** @return array<string, string> Only the start order and its mirror are indexed */
    protected function sortableFields(): array
    {
        return [HilosStatusHistoryTableRow::startedAt => HilosStatusHistoryTableRow::startedAt];
    }

    /** @return array<string, string> The normalized title searches saved component_name and maintenance title */
    protected function searchableFields(): array
    {
        return [HilosStatusHistoryTableRow::title => HilosStatusHistoryTableRow::title];
    }

    /**
     * @param TableQueryDTO $query Whitelisted, search-scoped viewport descriptor
     * @return TableSnapshotDTO Bounded merged window with common tuple counts and anchors
     * @throws HilosException When filters, anchors or source reads fail
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $limit = $query->limit <= TableConstants::NO_LIMIT ? $this->windowSize() : min($query->limit, $this->windowSize());
        $served = $query->withLimit($limit);
        $set = self::historyQuery($served);
        $anchor = $query->anchor === null ? null : self::readAnchor($query->anchor);
        $count = self::countSet($set);
        $direction = $query->anchorDirection;
        $skip = 0;
        $take = $limit;
        if ($query->pageIndex !== null) {
            $plan = TableWindowPlan::numberedSlice($query->pageIndex, $limit, $count->count, $count->exact);
            if ($plan === null) {
                return new TableSnapshotDTO(totalCount: $count->count, totalExact: $count->exact, limit: $limit,
                    rowsBefore: $count->exact ? $count->count : null);
            }
            $anchor = null;
            $direction = $plan['direction'];
            $skip = $plan['skip'];
            $take = $plan['take'];
        }
        $position = self::position($set, $anchor, $direction, $take, $skip);
        $entries = self::entries($position);
        $window = array_slice($entries, $skip, $take);
        $near = $skip > 0 && isset($entries[$skip - 1]) ? new TableAnchorDTO($entries[$skip - 1]->anchor()->toArray())
            : ($anchor === null ? null : new TableAnchorDTO($anchor->toArray()));
        $far = null;
        if ($window !== []) {
            $lastWalk = $window[array_key_last($window)];
            $farEntry = $entries[$skip + $take] ?? self::entries(self::position($set, $lastWalk->anchor(), $direction, 1))[0] ?? null;
            $far = $farEntry === null ? null : new TableAnchorDTO($farEntry->anchor()->toArray());
        }
        if ($direction === TableAnchorDirection::Before) {
            $window = array_reverse($window);
        }
        $first = $window === [] ? null : new TableAnchorDTO($window[0]->anchor()->toArray());
        $last = $window === [] ? null : new TableAnchorDTO($window[array_key_last($window)]->anchor()->toArray());
        $rowsBefore = null;
        if ($count->exact) {
            $rowsBefore = TableWindowPlan::knownRowsBefore($served, $first, $count->count);
            if ($rowsBefore === null && $first !== null) {
                $rowsBefore = self::countSet(self::position($set, self::readAnchor($first), TableAnchorDirection::Before, 1))->count;
            }
        }
        return new TableSnapshotDTO(
            rows: array_map(HilosStatusHistoryTableRow::fromEntry(...), $window),
            totalCount: $count->count, totalExact: $count->exact, limit: $limit,
            firstAnchor: $first, lastAnchor: $last, rowsBefore: $rowsBefore,
            frame: $window === [] ? null : ($direction === TableAnchorDirection::Before
                ? new TableWindowFrameDTO($far, $near) : new TableWindowFrameDTO($near, $far)),
        );
    }

    /**
     * @param StatusHistoryQuery $query Shared source predicate and optional tuple side
     * @return TableFacetCountDTO Count capped once across the two sources
     * @throws DatabaseException When either source count fails
     */
    private static function countSet(StatusHistoryQuery $query): TableFacetCountDTO
    {
        $incidents = Hilos::$db->statusIncidents->historyCount($query);
        $periods = Hilos::$db->statusMaintenancePeriods->historyCount($query);
        $sum = $incidents->count + $periods->count;
        return new TableFacetCountDTO(min($sum, TableConstants::COUNT_CEILING),
            $incidents->exact && $periods->exact && $sum <= TableConstants::COUNT_CEILING);
    }

    /**
     * @param StatusHistoryQuery $query Bounded source prefix
     * @return list<StatusHistoryEntry> Merged snapshots in walk order, nearest to the requested edge or anchor first
     * @throws DatabaseException When a source read fails
     * @throws ValidationException When a source row has an invalid identity
     */
    private static function entries(StatusHistoryQuery $query): array
    {
        $entries = array_merge(Hilos::$db->statusIncidents->historyPortion($query)->entries,
            Hilos::$db->statusMaintenancePeriods->historyPortion($query)->entries);
        $sign = $query->readsAscending() ? 1 : -1;
        usort($entries, static fn(StatusHistoryEntry $a, StatusHistoryEntry $b): int => self::compare($a->anchor(), $b->anchor()) * $sign);
        return $entries;
    }

    /**
     * @param StatusHistoryAnchor $a First numeric tuple
     * @param StatusHistoryAnchor $b Second numeric tuple
     * @return int Natural ascending tuple comparison
     */
    private static function compare(StatusHistoryAnchor $a, StatusHistoryAnchor $b): int
    {
        return ($a->startedAt <=> $b->startedAt) ?: ($a->kindRank <=> $b->kindRank) ?: ($a->sourceId <=> $b->sourceId);
    }

    /**
     * @param HilosStatusHistoryTableRow $row Normalized completed row
     * @return TableAnchorDTO Standard envelope carrying the numeric tuple
     */
    private static function rowAnchor(HilosStatusHistoryTableRow $row): TableAnchorDTO
    {
        return new TableAnchorDTO([
            HilosStatusHistoryTableRow::startedAt => $row->startedAt,
            'kindRank' => $row->kind === StatusHistoryEntry::INCIDENT
                ? StatusHistoryAnchor::INCIDENT_RANK : StatusHistoryAnchor::MAINTENANCE_RANK,
            HilosStatusHistoryTableRow::sourceId => $row->sourceId,
        ]);
    }

    /**
     * @param TableAnchorDTO $anchor Browser-returned numeric tuple
     * @return StatusHistoryAnchor Validated position, without looking up a possibly deleted row
     * @throws ValidationException When the tuple contains missing, extra or malformed values
     */
    private static function readAnchor(TableAnchorDTO $anchor): StatusHistoryAnchor
    {
        $time = $anchor->values[HilosStatusHistoryTableRow::startedAt] ?? null;
        $rank = $anchor->values['kindRank'] ?? null;
        $id = $anchor->values[HilosStatusHistoryTableRow::sourceId] ?? null;
        if (count($anchor->values) !== self::ANCHOR_VALUE_COUNT || !is_int($time) || !is_int($rank) || !is_int($id)) {
            throw new ValidationException('Choose a valid history position.');
        }
        return new StatusHistoryAnchor($time, $rank, $id);
    }

    /**
     * @param TableQueryDTO $query Whitelisted order, or null for the default
     * @return string Canonical tuple direction
     */
    private static function order(TableQueryDTO $query): string
    {
        return $query->sort?->components[0]->direction ?? TableConstants::ORDER_DESC;
    }

    /**
     * @param StatusHistoryQuery $set Shared, unpositioned source predicate
     * @param ?StatusHistoryAnchor $anchor Complete tuple boundary
     * @param TableAnchorDirection $direction Side to read
     * @param int $take Maximum merged rows
     * @param int $skip Bounded merged edge skip
     * @return StatusHistoryQuery Same predicate at a new position
     * @throws ValidationException When the window request is invalid
     */
    private static function position(
        StatusHistoryQuery $set,
        ?StatusHistoryAnchor $anchor,
        TableAnchorDirection $direction,
        int $take,
        int $skip = 0,
    ): StatusHistoryQuery {
        return new StatusHistoryQuery($set->term, $set->componentKey, $set->outcome, $set->fromMs, $set->toExclusiveMs,
            $set->order, $anchor, $direction, $take, $skip);
    }

    /**
     * @param TableQueryDTO $query Search-scoped set, independent of its viewport address
     * @return StatusHistoryQuery Strict filters and calendar start boundaries
     * @throws ValidationException When a filter, date, zone or order is invalid
     */
    private static function historyQuery(TableQueryDTO $query): StatusHistoryQuery
    {
        $zone = $query->filter[self::FILTER_TIME_ZONE] ?? TimeConstants::UTC_TIME_ZONE;
        if (!is_string($zone) || !BrowserTimeZone::isValid($zone)
            || (array_key_exists(self::FILTER_TIME_ZONE, $query->filter) && $query->filter[self::FILTER_TIME_ZONE] === null)) {
            throw new ValidationException('Choose a valid time zone for the history period.');
        }
        $timeZone = new DateTimeZone($zone);
        $from = self::filterDay($query, self::FILTER_FROM, $timeZone);
        $to = self::filterDay($query, self::FILTER_TO, $timeZone);
        return new StatusHistoryQuery(
            term: $query->search,
            componentKey: self::filterText($query, self::FILTER_COMPONENT_KEY),
            outcome: self::filterText($query, self::FILTER_OUTCOME),
            fromMs: $from === null ? null : $from->getTimestamp() * TimeConstants::MS_PER_SECOND,
            toExclusiveMs: $to === null ? null : $to->modify('+1 day')->getTimestamp() * TimeConstants::MS_PER_SECOND,
            order: self::order($query),
        );
    }

    /**
     * @param TableQueryDTO $query Filtered set
     * @param string $key Optional string filter
     * @return ?string Supplied nonempty text, or null for an absent filter
     * @throws ValidationException When a supplied filter is not nonempty text
     */
    private static function filterText(TableQueryDTO $query, string $key): ?string
    {
        if (!array_key_exists($key, $query->filter)) {
            return null;
        }
        $value = $query->filter[$key];
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException('Choose a valid history filter.');
        }
        return $value;
    }

    /**
     * @param TableQueryDTO $query Filtered set
     * @param string $key Calendar bound
     * @param DateTimeZone $zone Reader's validated IANA zone
     * @return ?DateTimeImmutable Start of the exact calendar day, null for an open boundary
     * @throws ValidationException When the bound is not a real date in YYYY-MM-DD format
     */
    private static function filterDay(TableQueryDTO $query, string $key, DateTimeZone $zone): ?DateTimeImmutable
    {
        $value = $query->filter[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts) !== 1
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new ValidationException('Choose a valid date for the history period.');
        }
        return DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone)
            ?: throw new ValidationException('Choose a valid date for the history period.');
    }
}

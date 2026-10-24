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
use Hilos\Core\Table\Definition\MultiRowViewportTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Status\StatusSnapshot;
use Hilos\Utils\Logger;

/** Read-only catalog rows; source facts update their values through the ordinary viewport classifier. */
final class HilosStatusComponentsTable extends TableDefinition implements MultiRowViewportTable
{
    public const string TABLE = 'hilosStatusComponents';
    public const string SLOT_COMPONENT = 'component';
    public const string FILTER_STATE = HilosStatusComponentsTableRow::state;

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            [BrowserSourceKey::TYPE => BrowserSourceType::RT, BrowserSourceKey::KEY => HilosStatusComponent::RT_COLLECTION],
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusIncidents],
            [BrowserSourceKey::TYPE => BrowserSourceType::RT, BrowserSourceKey::KEY => ProtectedModeRuntime::RT_ITEM],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT, BrowserSourceKey::KEY => HilosStatusComponent::RT_COLLECTION,
                ],
                BrowserTableFieldKey::ROW_KEY => HilosStatusComponent::componentKey,
                BrowserTableFieldKey::FIELDS => [HilosStatusComponent::componentKey => HilosStatusComponentsTableRow::componentKey],
                BrowserTableFieldKey::COMPUTED => [HilosStatusComponentsTableRow::name, HilosStatusComponentsTableRow::description,
                    HilosStatusComponentsTableRow::agentType, HilosStatusComponentsTableRow::state],
                BrowserTableFieldKey::NOT_PERSONAL => [HilosStatusComponentsTableRow::componentKey, HilosStatusComponentsTableRow::name,
                    HilosStatusComponentsTableRow::description, HilosStatusComponentsTableRow::agentType, HilosStatusComponentsTableRow::state],
            ],
            // These dependencies are observed through ROWS too; SOURCES alone declares no browser interest.
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::statusIncidents,
                ],
                BrowserTableFieldKey::ROW_KEY => StatusIncident::componentKey,
                BrowserTableFieldKey::COMPUTED => [HilosStatusComponentsTableRow::state],
                BrowserTableFieldKey::NOT_PERSONAL => [HilosStatusComponentsTableRow::state],
            ],
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT, BrowserSourceKey::KEY => ProtectedModeRuntime::RT_ITEM,
                ],
                BrowserTableFieldKey::ROW_KEY => ProtectedModeRuntime::phase,
                BrowserTableFieldKey::COMPUTED => [HilosStatusComponentsTableRow::state],
                BrowserTableFieldKey::NOT_PERSONAL => [HilosStatusComponentsTableRow::state],
            ],
        ],
    ];

    private const int WINDOW_ROWS = 25;

    /** @return int Server-declared initial window size */
    public function windowSize(): int
    {
        return self::WINDOW_ROWS;
    }

    /**
     * Empty sort vocabularies currently mean no opinion to the generic whitelist.
     * This catalog has only its declaration order, so an unsupported sort loses its ordering here.
     *
     * @param TableQueryDTO $query Requested window
     * @return TableSnapshotDTO Component rows in declaration order
     * @throws HilosException When the source projection or search cannot be read
     * @throws TableSearchNotSupportedException When search cannot be scoped
     */
    public function getPage(TableQueryDTO $query): TableSnapshotDTO
    {
        if ($query->sort !== null) {
            Logger::warning('Table sort order rejected', ['context' => self::class]);
            $query = new TableQueryDTO(search: $query->search, limit: $query->limit, filter: $query->filter,
                anchor: $query->anchor, anchorDirection: $query->anchorDirection, pageIndex: $query->pageIndex,
                shownFields: $query->shownFields);
        }
        return parent::getPage($query);
    }

    /**
     * @param SourceChange $change Committed counts, incident or freeze fact
     * @return list<TableRowMutationDTO> Updates to declared rows, never catalog creates or deletes
     * @throws HilosException When current sources or catalog declarations cannot be read
     */
    public function buildMutationsForSourceEvent(SourceChange $change): array
    {
        if ($change->isRt() && $change->sourceKey === HilosStatusComponent::RT_COLLECTION) {
            $key = $change->mutationType === TableMutationType::Clear ? null : $change->sourceId;
        } elseif ($change->isDb() && $change->sourceKey === HilosDbContext::statusIncidents) {
            $key = $this->incidentComponentKey($change);
        } elseif ($change->isRt() && $change->sourceKey === ProtectedModeRuntime::RT_ITEM) {
            $key = null;
        } else {
            return [];
        }

        $mutations = [];
        foreach (StatusSnapshot::read()->components as $componentKey => $component) {
            if ($key === null || $key === $componentKey) {
                $mutations[] = $this->mutation(TableMutationType::Update, $componentKey,
                    HilosStatusComponentsTableRow::fromSnapshot($component));
            }
        }
        return $mutations;
    }

    /**
     * @param AbstractTableRow $row Current component row
     * @return array{rowKey: int|string, sources: array<string, mixed>, staleSources?: list<string>} Internal browser envelope
     * @throws TableRowKeyMissingException When a row lacks its declared key
     * @throws HilosException When a source's freshness cannot be read
     */
    public function browserRow(AbstractTableRow $row): array
    {
        $key = $row->requireRowKey();
        $envelope = [BrowserPageSignalData::rowKey => $key,
            BrowserPageSignalData::sources => [self::SLOT_COMPONENT => $row->toArray()]];
        if (Hilos::$rt !== null && (isset(Hilos::$rt->hilosStatusComponents[$key])
            && Hilos::$rt->hilosStatusComponents[$key]->staleSince() !== null
            || Hilos::$rt->hilosProtectedModeRuntime?->staleSince() !== null)) {
            $envelope[BrowserPageSignalData::staleSources] = [self::SLOT_COMPONENT];
        }
        return $envelope;
    }

    /** @return array<string, WireField> All fields derive from catalog content and runtime verdicts */
    public function wireFields(): array
    {
        return array_fill_keys([HilosStatusComponentsTableRow::componentKey, HilosStatusComponentsTableRow::name,
            HilosStatusComponentsTableRow::description, HilosStatusComponentsTableRow::agentType,
            HilosStatusComponentsTableRow::state], WireField::notPersonal());
    }

    /**
     * @param string|int $rowKey Catalog key
     * @param TableQueryDTO $query Window membership, independent of pagination
     * @return bool Whether the component remains in the filtered and searched set
     * @throws HilosException When the current projection or search cannot be read
     * @throws TableSearchNotSupportedException When search cannot be scoped
     * @throws TableSearchFieldUnknownException When a searched field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): bool
    {
        return $this->containsRowInMemory($this->collectRows($query), $rowKey, $this->scopeSearch($query));
    }

    /**
     * @param TableQueryDTO $query Current filter and search
     * @param array<string, list<int|float|string|bool>> $wanted State choices to count
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> State facet counts
     * @throws HilosException When the source projection or search cannot be read
     * @throws TableSearchNotSupportedException When search cannot be scoped
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): array
    {
        return TableFacetTally::forFilters($this->scopeSearch($query), array_intersect_key($wanted, [self::FILTER_STATE => true]),
            function (TableQueryDTO $set): TableFacetCountDTO {
                $snapshot = $this->getPage($set);
                return new TableFacetCountDTO($snapshot->totalCount, $snapshot->totalExact);
            });
    }

    /** Configures the component row without table or item mutation actions. */
    protected function init(): void
    {
        $this->setRowClass(HilosStatusComponentsTableRow::class);
    }

    /** @return array<string, string> Public catalog fields searched in memory */
    protected function searchableFields(): array
    {
        return [HilosStatusComponentsTableRow::name => HilosStatusComponentsTableRow::name,
            HilosStatusComponentsTableRow::description => HilosStatusComponentsTableRow::description,
            HilosStatusComponentsTableRow::agentType => HilosStatusComponentsTableRow::agentType];
    }

    /**
     * @param TableQueryDTO $query Scoped search, state filter and window address
     * @return TableSnapshotDTO Standard in-memory viewport
     * @throws HilosException When the source projection or search cannot be read
     * @throws TableSearchNotSupportedException When search cannot be scoped
     * @throws TableSearchFieldUnknownException When a searched field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->collectRows($query), $query);
    }

    /**
     * @param TableQueryDTO $query State membership
     * @return list<array<string, mixed>> Temporary row payloads, preserving declaration order
     * @throws HilosException When source wiring or the component catalog is invalid
     */
    private function collectRows(TableQueryDTO $query): array
    {
        $rows = [];
        foreach (StatusSnapshot::read()->components as $component) {
            if (isset($query->filter[self::FILTER_STATE]) && $query->filter[self::FILTER_STATE] !== $component->state->value) {
                continue;
            }
            $rows[] = HilosStatusComponentsTableRow::fromSnapshot($component)->toArray();
        }
        return $rows;
    }

    /**
     * @param SourceChange $change Incident fact, possibly carrying only a changed column
     * @return ?string Known component key, or null to recompute every declared component
     */
    private function incidentComponentKey(SourceChange $change): ?string
    {
        if ($change->mutationType === TableMutationType::Clear) {
            return null;
        }
        foreach ([$change->row, $change->previous] as $fields) {
            $key = $fields[EntityStatusIncident::component_key] ?? $fields[StatusIncident::componentKey]
                ?? $fields[EntityStatusIncident::active_component_key] ?? $fields[StatusIncident::activeComponentKey] ?? null;
            if (is_string($key)) {
                return $key;
            }
        }
        if (!ctype_digit($change->sourceId) || Hilos::$db === null || Hilos::$rt?->hilosProtectedModeRuntime === null
            || Hilos::$rt->hilosProtectedModeRuntime->silencesUnstoppedWriters()) {
            return null;
        }
        try {
            return (Hilos::$db->statusIncidents[(int) $change->sourceId] ?? null)?->componentKey;
        } catch (DatabaseException) {
            return null;
        }
    }
}

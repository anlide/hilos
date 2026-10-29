<?php

declare(strict_types=1);

namespace Hilos\Tables\ProtectedMode;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\MultiRowViewportTable;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
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
use Hilos\Hilos;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceNodesPage;
use Hilos\ProtectedMode\Exception\ProtectedModeException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/** Read-only viewport over the local master's node-report projection, without a second publication cache. */
class HilosMaintenanceNodesTable extends TableDefinition implements SelfSnapshotTable, MultiRowViewportTable
{
    public const string TABLE = 'hilosMaintenanceNodes';
    public const string ROW_SLOT = 'node';
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            [BrowserSourceKey::TYPE => BrowserSourceType::RT, BrowserSourceKey::KEY => ProtectedModeRuntime::RT_ITEM],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT, BrowserSourceKey::KEY => ProtectedModeRuntime::RT_ITEM,
                ],
                BrowserTableFieldKey::ROW_KEY => ProtectedModeRuntime::phase,
                BrowserTableFieldKey::COMPUTED => [HilosMaintenanceNodesTableRow::nodeId, HilosMaintenanceNodesTableRow::phase,
                    HilosMaintenanceNodesTableRow::quiesced, HilosMaintenanceNodesTableRow::isLeader,
                    HilosMaintenanceNodesTableRow::isInitiator, HilosMaintenanceNodesTableRow::reportState],
                BrowserTableFieldKey::NOT_PERSONAL => [HilosMaintenanceNodesTableRow::nodeId, HilosMaintenanceNodesTableRow::phase,
                    HilosMaintenanceNodesTableRow::quiesced, HilosMaintenanceNodesTableRow::isLeader,
                    HilosMaintenanceNodesTableRow::isInitiator, HilosMaintenanceNodesTableRow::reportState],
            ],
        ],
    ];

    private const int WINDOW_ROWS = 25;

    /** @return int Rows in the initial window */
    public function windowSize(): int
    {
        return self::WINDOW_ROWS;
    }

    /** @return TableSortOrderDTO Initial node identity order */
    public function defaultSort(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosMaintenanceNodesTableRow::nodeId));
    }

    /**
     * @param SourceChange $change Committed runtime fact with previous projection values
     * @return list<TableRowMutationDTO> Creates, value updates and removals by nodeId
     * @throws ProtectedModeException When this process has no published projection
     * @throws PageResourceNotFoundException When cluster mode is disabled
     * @throws InvalidFormatException When a projected row is malformed
     */
    public function buildMutationsForSourceEvent(SourceChange $change): array
    {
        if (!$change->isRt() || $change->sourceKey !== ProtectedModeRuntime::RT_ITEM
            || !array_key_exists(ProtectedModeRuntime::maintenanceNodes, $change->row)) {
            return [];
        }

        $current = array_column($this->nodes(), null, HilosMaintenanceNodesTableRow::nodeId);
        $previous = array_column($change->previous[ProtectedModeRuntime::maintenanceNodes] ?? [], null,
            HilosMaintenanceNodesTableRow::nodeId);
        $mutations = [];
        foreach ($current as $nodeId => $node) {
            if (!isset($previous[$nodeId]) || $previous[$nodeId] !== $node) {
                $mutations[] = $this->mutation(isset($previous[$nodeId]) ? TableMutationType::Update : TableMutationType::Create,
                    $nodeId, HilosMaintenanceNodesTableRow::fromArray($node));
            }
        }
        foreach (array_diff_key($previous, $current) as $nodeId => $node) {
            $mutations[] = $this->mutation(TableMutationType::Delete, $nodeId);
        }
        return $mutations;
    }

    /**
     * @param AbstractTableRow $row Node report
     * @return array{rowKey: int|string, sources: array<string, mixed>} Standard browser envelope
     * @throws TableRowKeyMissingException When the row carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()]];
    }

    /** @return array<string, WireField> Runtime reports contain no personal fields */
    public function wireFields(): array
    {
        return array_fill_keys(self::BROWSER[BrowserTableConfigKey::ROWS][0][BrowserTableFieldKey::COMPUTED], WireField::notPersonal());
    }

    /** Configures typed rows without mutation actions. */
    protected function init(): void
    {
        $this->setRowClass(HilosMaintenanceNodesTableRow::class);
    }

    /** @return array<string, string> Only node identities are sortable, in either direction */
    protected function sortableFields(): array
    {
        return [HilosMaintenanceNodesTableRow::nodeId => HilosMaintenanceNodesTableRow::nodeId];
    }

    /**
     * @param TableQueryDTO $query Standard window address and node order
     * @return TableSnapshotDTO Window over the current projection
     * @throws ProtectedModeException When this process has no published projection
     * @throws PageResourceNotFoundException When cluster mode is disabled
     * @throws TableSearchNotSupportedException When a search term is supplied
     * @throws TableSearchFieldUnknownException When search names an absent field
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->nodes(), $query);
    }

    /**
     * Null means this process has no published projection; an empty list is an authoritative empty set.
     *
     * @return list<array<string, string|bool|null>> Current node reports
     * @throws PageResourceNotFoundException When cluster mode is disabled
     * @throws ProtectedModeException When the master has not published or this process has no runtime state
     */
    private function nodes(): array
    {
        if (!AbstractHilosMaintenanceNodesPage::isTabApplicable(new PageRouteParams([]))) {
            throw new PageResourceNotFoundException('Cluster nodes are unavailable on this installation');
        }
        return Hilos::$rt?->hilosProtectedModeRuntime?->maintenanceNodes
            ?? throw new ProtectedModeException('Cluster maintenance reports are not available yet');
    }
}

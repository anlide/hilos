<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Cluster\Exception\ClusterException;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\ClusterEnvironmentCellState;
use Hilos\DaemonSection\ClusterEnvironmentComparison;
use Hilos\DaemonSection\ClusterEnvironmentKeyComparison;
use Hilos\DaemonSection\NodeEnvironmentReading;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Pages\Daemon\AbstractHilosDaemonEnvMismatchPage;
use Hilos\Runtime\State\Item\HilosClusterNode;

/**
 * Rows are verdict buckets from the page worker's picture mirror; the page has no separate verdict.
 * Labels come from the collector, never a node hash. The only value comes from the serving
 * page agent's process, never for a sensitive key and hidden from a viewer unless its catalog
 * opens it. {@see AbstractHilosDaemonEnvMismatchPage::onPictureChanged()} resends whole windows.
 */
abstract class AbstractHilosDaemonEnvMismatchTable extends TableDefinition implements ViewportTable
{
    private const string ROW_SLOT = 'key';

    /** @return ?TableRowMutationDTO The mirror raises no row source events */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Compared key row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser row envelope
     * @throws TableRowKeyMissingException When a placeholder row has no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /** @return array<string, WireField> Every row field is already masked for this viewer */
    public function wireFields(): array
    {
        return [
            HilosDaemonEnvMismatchTableRow::rowKey => WireField::notPersonal(),
            HilosDaemonEnvMismatchTableRow::key => WireField::notPersonal(),
            HilosDaemonEnvMismatchTableRow::sensitive => WireField::notPersonal(),
            HilosDaemonEnvMismatchTableRow::value => WireField::notPersonal(),
            HilosDaemonEnvMismatchTableRow::cells => WireField::each([
                HilosDaemonEnvMismatchTableRow::CELL_NODE_ID => WireField::notPersonal(),
                HilosDaemonEnvMismatchTableRow::CELL_STATE => WireField::notPersonal(),
                HilosDaemonEnvMismatchTableRow::CELL_TYPE => WireField::notPersonal(),
                HilosDaemonEnvMismatchTableRow::CELL_SOURCE => WireField::notPersonal(),
                HilosDaemonEnvMismatchTableRow::CELL_DIGEST => WireField::notPersonal(),
            ]),
        ];
    }

    /** @return ?bool Unknown: the mirror raises no row source events */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return null;
    }

    /**
     * @param list<ClusterEnvironmentKeyComparison> $keys One verdict bucket in catalog order
     * @param string $servingNodeId Node running the page agent
     * @param EnvAccessor $env That process's environment
     * @param bool $viewer Whether the window belongs to an admin view-mode viewer
     * @return list<array<string, mixed>> Rows ready for the window
     * @throws EnvException When the process catalog or value cannot be read
     */
    public static function rowsOf(array $keys, string $servingNodeId, EnvAccessor $env, bool $viewer): array
    {
        $catalog = array_fill_keys($env->catalogKeys(), true);
        $rows = [];
        foreach ($keys as $comparison) {
            $key = $comparison->key;
            $declared = isset($catalog[$key]);
            $sensitive = $declared && $env->sensitiveFor($key);
            $servingKnown = false;
            $cells = [];
            foreach ($comparison->cells as $cell) {
                $fingerprint = $cell->fingerprint;
                $cells[] = [
                    HilosDaemonEnvMismatchTableRow::CELL_NODE_ID => $cell->nodeId,
                    HilosDaemonEnvMismatchTableRow::CELL_STATE => $cell->state->value,
                    HilosDaemonEnvMismatchTableRow::CELL_TYPE => $fingerprint?->type,
                    HilosDaemonEnvMismatchTableRow::CELL_SOURCE => $fingerprint?->source->value,
                    HilosDaemonEnvMismatchTableRow::CELL_DIGEST => $fingerprint?->digest,
                ];
                if ($cell->nodeId === $servingNodeId && $cell->state === ClusterEnvironmentCellState::Known) {
                    $servingKnown = true;
                }
            }

            $value = null;
            if ($servingKnown && $declared && !$sensitive) {
                $value = NodeEnvironmentReading::valueView(
                    $env->resolutionFor($key),
                    $viewer && !$env->visibleInAdminViewMode($key),
                    false,
                );
            }
            $rows[] = [
                HilosDaemonEnvMismatchTableRow::rowKey => $key,
                HilosDaemonEnvMismatchTableRow::key => $key,
                HilosDaemonEnvMismatchTableRow::sensitive => $sensitive,
                HilosDaemonEnvMismatchTableRow::value => $value,
                HilosDaemonEnvMismatchTableRow::cells => $cells,
            ];
        }

        return $rows;
    }

    /**
     * @param ClusterEnvironmentComparison $comparison Current picture verdict
     * @return list<ClusterEnvironmentKeyComparison> This table's bucket
     */
    abstract protected function keysOf(ClusterEnvironmentComparison $comparison): array;

    /**
     * @param TableQueryDTO $query Window and viewer scope
     * @return TableSnapshotDTO Rows in verdict order
     * @throws EnvException When the process catalog or value cannot be read
     * @throws ClusterException When the serving node cannot be identified
     * @throws InvalidArgumentException When the picture comparison is invalid
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->collectRows($query), $query);
    }

    /** Configures the compared-key row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosDaemonEnvMismatchTableRow::class);
    }

    /**
     * @param TableQueryDTO $query Viewer scope
     * @return list<array<string, mixed>> Rows from the appropriate verdict bucket
     * @throws EnvException When the process catalog or value cannot be read
     * @throws ClusterException When the serving node cannot be identified
     * @throws InvalidArgumentException When the picture comparison is invalid
     */
    private function collectRows(TableQueryDTO $query): array
    {
        $comparison = ClusterDaemonPictureMirror::picture()?->environmentComparison();
        if ($comparison === null) {
            return [];
        }

        $serving = Hilos::$cluster?->localNodeId() ?? HilosClusterNode::STANDALONE_NODE_ID;

        return self::rowsOf($this->keysOf($comparison), $serving, Hilos::$env, $query->shownFields !== null);
    }
}

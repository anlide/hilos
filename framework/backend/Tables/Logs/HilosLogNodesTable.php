<?php

declare(strict_types=1);

namespace Hilos\Tables\Logs;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndexMirror;

/**
 * Cluster nodes projected from the page worker's log mirror into ordinary bounded windows.
 * The mirror raises no SourceChange; the overview page re-serves held windows when its picture changes.
 */
final class HilosLogNodesTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosLogNodes';

    /** Inline fragment slot; its nodeId remains a row key rather than an entity id. */
    public const string ROW_SLOT = 'node';

    /** @return int Rows in the first node window */
    public function windowSize(): int
    {
        return 25;
    }

    /** @return ?TableSortOrderDTO Deterministic node identity order */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosLogNodesTableRow::nodeId, TableConstants::ORDER_ASC));
    }

    /**
     * @param SourceChange $change Source event, ignored by this mirror-backed table
     * @return ?TableRowMutationDTO Always null; updates re-project the window
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Node row from this table's window
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder without a key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /** @return array<string, WireField> Non-personal node measurements for admin view mode */
    public function wireFields(): array
    {
        return [
            HilosLogNodesTableRow::nodeId => WireField::notPersonal(),
            HilosLogNodesTableRow::available => WireField::notPersonal(),
            HilosLogNodesTableRow::lastRotationAt => WireField::notPersonal(),
            HilosLogNodesTableRow::liveBytes => WireField::notPersonal(),
            HilosLogNodesTableRow::archiveBytes => WireField::notPersonal(),
            HilosLogNodesTableRow::growthBytesPerDay => WireField::notPersonal(),
            HilosLogNodesTableRow::batchesDueForTakeout => WireField::notPersonal(),
        ];
    }

    /** @return array<string, string> Server vocabulary for the default node identity order */
    protected function sortableFields(): array
    {
        return [HilosLogNodesTableRow::nodeId => HilosLogNodesTableRow::nodeId];
    }

    /**
     * Keeps the mirror's nodeId order: the generic value comparator uses natural, case-insensitive
     * ordering, whereas the cluster index sorts node identities as array keys. The source-order
     * path still applies the ordinary anchor, page number and limit without reordering identities.
     *
     * @param TableQueryDTO $query Window size, order and anchor or page number
     * @return TableSnapshotDTO Bounded node rows and the exact set size
     * @throws EnvException When the cluster mode flag cannot be read
     * @throws TableSearchNotSupportedException When a search term is supplied to this unsearchable table
     * @throws TableSearchFieldUnknownException When a declared search field is absent from the rows
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = $this->collectRows();
        if ($query->sort?->last()->direction === TableConstants::ORDER_DESC) {
            $rows = array_reverse($rows);
        }

        return $this->filterInMemory($rows, new TableQueryDTO(
            search: $query->search,
            limit: $query->limit,
            filter: $query->filter,
            anchor: $query->anchor,
            anchorDirection: $query->anchorDirection,
            pageIndex: $query->pageIndex,
            searchableFields: $query->searchableFields,
            shownFields: $query->shownFields,
        ));
    }

    /** Configures the inline node row contract. */
    protected function init(): void
    {
        $this->setRowClass(HilosLogNodesTableRow::class);
    }

    /**
     * @return list<array<string, mixed>> Full projected set, in the mirror's nodeId order
     * @throws EnvException When the cluster mode flag cannot be read
     */
    private function collectRows(): array
    {
        if (Hilos::$cluster?->isEnabled() !== true) {
            return [];
        }

        $rows = [];
        foreach (ClusterLogIndexMirror::index()?->nodes() ?? [] as $slot) {
            $rows[] = HilosLogNodesTableRow::fromNode($slot)->toArray();
        }

        return $rows;
    }
}

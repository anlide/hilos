<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\LogStreamConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
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
use Hilos\Pages\Daemon\AbstractHilosDaemonWorkersPage;

/**
 * Workers of one node, projected only from the page worker's daemon picture mirror.
 *
 * A viewport without a node filter is empty. The mirror has no row change source, so
 * {@see AbstractHilosDaemonWorkersPage::onPictureChanged()} resends complete windows
 * on a changed process roster rather than waiting for a tick.
 */
final class HilosDaemonWorkersTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosDaemonWorkers';
    public const string FILTER_NODE = 'node';

    private const string ROW_SLOT = 'worker';
    private const int WINDOW_SIZE = 25;

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /**
     * The mirror raises no row source events.
     *
     * @param SourceChange $change Ignored source change
     * @return ?TableRowMutationDTO Always null
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Worker table row
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

    /** @return array<string, WireField> Origins of every non-personal worker field */
    public function wireFields(): array
    {
        return [
            HilosDaemonWorkersTableRow::rowKey => WireField::notPersonal(),
            HilosDaemonWorkersTableRow::index => WireField::notPersonal(),
            HilosDaemonWorkersTableRow::kind => WireField::notPersonal(),
            HilosDaemonWorkersTableRow::pid => WireField::notPersonal(),
            HilosDaemonWorkersTableRow::memoryBytes => WireField::notPersonal(),
            HilosDaemonWorkersTableRow::agentCount => WireField::notPersonal(),
            HilosDaemonWorkersTableRow::agentIds => WireField::notPersonal(),
            HilosDaemonWorkersTableRow::logStream => WireField::notPersonal(),
        ];
    }

    /**
     * @param string|int $rowKey Row key to find
     * @param TableQueryDTO $query Window filter and search scope
     * @return ?bool Whether the node's picture contains this row
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->collectRows($query), $rowKey, $query);
    }

    /**
     * @param TableQueryDTO $query Window query
     * @return TableSnapshotDTO Rows in picture order
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->collectRows($query), $query);
    }

    /** Configures the worker row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosDaemonWorkersTableRow::class);
    }

    /**
     * @param TableQueryDTO $query Window filter naming one node
     * @return list<array<string, mixed>> Workers in the node's picture order
     */
    private function collectRows(TableQueryDTO $query): array
    {
        $node = $query->filter[self::FILTER_NODE] ?? null;
        if (!is_string($node) || trim($node) === '') {
            return [];
        }

        $roster = ClusterDaemonPictureMirror::picture()?->node(trim($node))?->slot?->picture->processes;
        if ($roster === null) {
            return [];
        }

        $rows = [];
        foreach ($roster->workers as $worker) {
            $agentIds = array_map(static fn ($agent): string => $agent->id, $worker->agents);
            $rows[] = [
                HilosDaemonWorkersTableRow::rowKey => self::rowKey($worker->kind, $worker->index),
                HilosDaemonWorkersTableRow::index => $worker->index,
                HilosDaemonWorkersTableRow::kind => $worker->kind,
                HilosDaemonWorkersTableRow::pid => $worker->pid,
                HilosDaemonWorkersTableRow::memoryBytes => $worker->memoryBytes,
                HilosDaemonWorkersTableRow::agentCount => count($agentIds),
                HilosDaemonWorkersTableRow::agentIds => $agentIds,
                HilosDaemonWorkersTableRow::logStream => self::logStream($worker->kind, $worker->index),
            ];
        }

        return $rows;
    }

    /** @return string Worker key in the master's key format */
    private static function rowKey(string $kind, int $index): string
    {
        return $kind . WorkerConstants::KEY_SEPARATOR . $index;
    }

    /** @return string Live log stream named by the master's writer */
    private static function logStream(string $kind, int $index): string
    {
        return LogStreamConstants::WORKER_STREAM_PREFIX . $kind . LogStreamConstants::WORKER_TYPE_SEPARATOR . $index
            . LogStreamConstants::STREAM_SUFFIX;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\AdminViewMode\WireField;
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
use Hilos\DaemonSection\LocalNodeEnvironment;
use Hilos\DaemonSection\NodeEnvironmentReading;

/**
 * Orphan rows come only from the node agent's local masked reading, never from
 * the mirror. The agent's environment refresh re-answers this page and window;
 * this table has no row change source of its own.
 */
final class HilosDaemonEnvOrphansTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosDaemonEnvOrphans';
    public const string FILTER_NODE = 'node';

    private const string ROW_SLOT = 'orphan';
    private const int WINDOW_SIZE = 25;
    private const int SIMILAR_MAX_DISTANCE = 2;

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /** @return ?TableRowMutationDTO No independent row source */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Orphan row
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

    /** @return array<string, WireField> Masked non-personal orphan fields */
    public function wireFields(): array
    {
        return [
            HilosDaemonEnvOrphansTableRow::rowKey => WireField::notPersonal(),
            HilosDaemonEnvOrphansTableRow::key => WireField::notPersonal(),
            HilosDaemonEnvOrphansTableRow::value => WireField::notPersonal(),
            HilosDaemonEnvOrphansTableRow::similarTo => WireField::notPersonal(),
        ];
    }

    /**
     * @param string|int $rowKey Orphan key
     * @param TableQueryDTO $query Window scope
     * @return ?bool Whether the masked set contains this key
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->collectRows($query), $rowKey, $query);
    }

    /**
     * @param TableQueryDTO $query Window scope
     * @return TableSnapshotDTO Rows in file order
     * @throws TableSearchNotSupportedException When search is requested
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->collectRows($query), $query);
    }

    /** Configures the orphan row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosDaemonEnvOrphansTableRow::class);
    }

    /**
     * @param TableQueryDTO $query Node and reader scope
     * @return list<array<string, mixed>> Masked orphan rows in file order
     */
    private function collectRows(TableQueryDTO $query): array
    {
        $node = $query->filter[self::FILTER_NODE] ?? null;
        if (!is_string($node) || trim($node) === '') {
            return [];
        }
        $reading = LocalNodeEnvironment::reading();
        if ($reading === null || $reading->nodeId !== trim($node)) {
            return [];
        }

        $view = $reading->view($query->shownFields !== null, false);
        $catalogKeys = array_column($view[NodeEnvironmentReading::KEYS], NodeEnvironmentReading::KEY);
        $rows = [];
        foreach ($view[NodeEnvironmentReading::ORPHANS] as $orphan) {
            $rows[] = [
                ...$orphan,
                HilosDaemonEnvOrphansTableRow::rowKey => $orphan[NodeEnvironmentReading::KEY],
                HilosDaemonEnvOrphansTableRow::similarTo => self::nearestCatalogKey(
                    $orphan[NodeEnvironmentReading::KEY],
                    $catalogKeys,
                ),
            ];
        }

        return $rows;
    }

    /**
     * @param string $orphan Uncataloged key
     * @param list<string> $catalogKeys Catalog names in declaration order
     * @return ?string Nearest name within the distance limit
     */
    private static function nearestCatalogKey(string $orphan, array $catalogKeys): ?string
    {
        $nearest = null;
        $distance = self::SIMILAR_MAX_DISTANCE + 1;
        foreach ($catalogKeys as $key) {
            $candidateDistance = levenshtein(strtoupper($orphan), strtoupper($key));
            if ($candidateDistance < $distance) {
                $nearest = $key;
                $distance = $candidateDistance;
            }
        }

        return $distance <= self::SIMILAR_MAX_DISTANCE ? $nearest : null;
    }
}

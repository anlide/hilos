<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

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
use Hilos\Database\ChangeLog\Section\ChangeLogNoisyColumn;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Frozen windows over live noise declarations, read anew on each request.
 */
class HilosChangeLogNoisyColumnsTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosChangeLogNoisyColumns';

    private const string ROW_SLOT = 'noisyColumn';
    private const int WINDOW_SIZE = 25;

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /**
     * @param SourceChange $change Source fact, deliberately ignored
     * @return ?TableRowMutationDTO No live mutations belong to this table
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Noise declaration row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser row envelope
     * @throws TableRowKeyMissingException When the row has no stable key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /** @return array<string, WireField> Schema metadata shared with every viewer */
    public function wireFields(): array
    {
        return [
            HilosChangeLogNoisyColumnsTableRow::columnKey => WireField::notPersonal(),
            HilosChangeLogNoisyColumnsTableRow::table => WireField::notPersonal(),
            HilosChangeLogNoisyColumnsTableRow::column => WireField::notPersonal(),
            HilosChangeLogNoisyColumnsTableRow::reason => WireField::notPersonal(),
        ];
    }

    /**
     * @param TableQueryDTO $query Scoped viewport request
     * @return TableSnapshotDTO Exact window ordered by table and column
     * @throws HilosException When live noise declarations cannot be read
     * @throws TableSearchNotSupportedException When a search is requested without declared fields
     * @throws TableSearchFieldUnknownException When a searched field is absent from the row set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = [];
        foreach ($this->noisyColumns() as $noisyColumn) {
            $rows[] = HilosChangeLogNoisyColumnsTableRow::fromColumn($noisyColumn)->toArray();
        }
        usort(
            $rows,
            static fn(array $a, array $b): int
                => strcmp($a[HilosChangeLogNoisyColumnsTableRow::table], $b[HilosChangeLogNoisyColumnsTableRow::table])
                ?: strcmp($a[HilosChangeLogNoisyColumnsTableRow::column], $b[HilosChangeLogNoisyColumnsTableRow::column]),
        );

        return $this->filterInMemory($rows, $query);
    }

    /** Configures the inline noise declaration row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosChangeLogNoisyColumnsTableRow::class);
    }

    /**
     * @return ChangeLogReadCollection<ChangeLogNoisyColumn> Live declarations for this request
     * @throws HilosException When the collection, journal SQL or metadata cannot be read
     */
    protected function noisyColumns(): ChangeLogReadCollection
    {
        return Hilos::$db->changeLogTables->sectionNoisyColumns();
    }
}

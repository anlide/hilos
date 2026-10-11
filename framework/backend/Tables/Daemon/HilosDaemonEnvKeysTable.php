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
use Hilos\Core\Table\TableSearchField;
use Hilos\DaemonSection\LocalNodeEnvironment;
use Hilos\DaemonSection\NodeEnvironmentReading;

/**
 * Catalog rows come only from the node agent's local reading, never from the mirror.
 * The agent's environment refresh re-answers the page and its windows through
 * BrowserContext::resendPageWhole(); this table has no separate row change source.
 * Viewer masking comes from the window, so search and attention read only shown data.
 */
final class HilosDaemonEnvKeysTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosDaemonEnvKeys';
    public const string FILTER_NODE = 'node';
    public const string FILTER_ATTENTION = 'attention';

    private const string ROW_SLOT = 'key';
    private const int WINDOW_SIZE = 25;

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
     * @param AbstractTableRow $row Catalog key row
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

    /** @return array<string, WireField> Masked non-personal fields of one key */
    public function wireFields(): array
    {
        return [
            HilosDaemonEnvKeysTableRow::rowKey => WireField::notPersonal(),
            HilosDaemonEnvKeysTableRow::key => WireField::notPersonal(),
            HilosDaemonEnvKeysTableRow::type => WireField::notPersonal(),
            HilosDaemonEnvKeysTableRow::required => WireField::notPersonal(),
            HilosDaemonEnvKeysTableRow::declaredBy => WireField::notPersonal(),
            HilosDaemonEnvKeysTableRow::sensitive => WireField::notPersonal(),
            HilosDaemonEnvKeysTableRow::perNode => WireField::notPersonal(),
            HilosDaemonEnvKeysTableRow::source => WireField::notPersonal(),
            HilosDaemonEnvKeysTableRow::value => WireField::notPersonal(),
            HilosDaemonEnvKeysTableRow::disk => WireField::notPersonal(),
            HilosDaemonEnvKeysTableRow::valueText => WireField::notPersonal(),
        ];
    }

    /** @return array<string, string|TableSearchField> Visible key mask and value text */
    protected function searchableFields(): array
    {
        return [
            HilosDaemonEnvKeysTableRow::key => TableSearchField::mask(HilosDaemonEnvKeysTableRow::key),
            HilosDaemonEnvKeysTableRow::valueText => HilosDaemonEnvKeysTableRow::valueText,
        ];
    }

    /**
     * @param string|int $rowKey Catalog key
     * @param TableQueryDTO $query Window scope
     * @return ?bool Whether the masked set contains this key
     * @throws TableSearchNotSupportedException When search is unavailable
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->collectRows($query), $rowKey, $query);
    }

    /**
     * @param TableQueryDTO $query Window scope
     * @return TableSnapshotDTO Rows in catalog order
     * @throws TableSearchNotSupportedException When search is unavailable
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->collectRows($query), $query);
    }

    /** Configures the catalog row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosDaemonEnvKeysTableRow::class);
    }

    /**
     * @param TableQueryDTO $query Node and reader scope
     * @return list<array<string, mixed>> Masked catalog rows in declaration order
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

        $rows = [];
        foreach ($reading->view($query->shownFields !== null, false)[NodeEnvironmentReading::KEYS] as $key) {
            if (($query->filter[self::FILTER_ATTENTION] ?? null) === true
                && !(($key[NodeEnvironmentReading::REQUIRED] && $key[NodeEnvironmentReading::SOURCE] === 'missing')
                    || $key[NodeEnvironmentReading::SOURCE] === 'example'
                    || $key[NodeEnvironmentReading::DISK] !== null)) {
                continue;
            }

            $value = $key[NodeEnvironmentReading::VALUE];
            $rows[] = [
                ...$key,
                HilosDaemonEnvKeysTableRow::rowKey => $key[NodeEnvironmentReading::KEY],
                HilosDaemonEnvKeysTableRow::valueText => ($value[NodeEnvironmentReading::KIND] ?? null)
                    === NodeEnvironmentReading::KIND_TEXT ? $value[NodeEnvironmentReading::TEXT] : null,
            ];
        }

        return $rows;
    }
}

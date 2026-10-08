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
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\Pages\Daemon\AbstractHilosDaemonCronPage;

/**
 * Cron rules of one node, projected only from the page worker's daemon picture mirror.
 *
 * A viewport without a node filter is empty. The mirror has no row change source, so
 * {@see AbstractHilosDaemonCronPage::onPictureChanged()} resends complete windows on a
 * changed cron portion rather than waiting for a tick.
 */
final class HilosDaemonCronTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosDaemonCron';
    public const string FILTER_NODE = 'node';

    private const string ROW_SLOT = 'rule';
    private const string ROW_KEY_SEPARATOR = '/';
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
     * @param AbstractTableRow $row Cron table row
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

    /** @return array<string, WireField> Origins of every non-personal cron field */
    public function wireFields(): array
    {
        return [
            HilosDaemonCronTableRow::rowKey => WireField::notPersonal(),
            HilosDaemonCronTableRow::agentId => WireField::notPersonal(),
            HilosDaemonCronTableRow::name => WireField::notPersonal(),
            HilosDaemonCronTableRow::expression => WireField::notPersonal(),
            HilosDaemonCronTableRow::lastRunAt => WireField::notPersonal(),
            HilosDaemonCronTableRow::nextRunAt => WireField::notPersonal(),
            HilosDaemonCronTableRow::idleReason => WireField::notPersonal(),
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

    /** Configures the cron row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosDaemonCronTableRow::class);
    }

    /**
     * @param TableQueryDTO $query Window filter naming one node
     * @return list<array<string, mixed>> Rules in the node's picture order
     */
    private function collectRows(TableQueryDTO $query): array
    {
        $node = $query->filter[self::FILTER_NODE] ?? null;
        if (!is_string($node) || trim($node) === '') {
            return [];
        }

        $cron = ClusterDaemonPictureMirror::picture()?->node(trim($node))?->slot?->picture->cron;
        if ($cron === null) {
            return [];
        }

        $rows = [];
        foreach ($cron->rules as $rule) {
            $rows[] = [
                HilosDaemonCronTableRow::rowKey => self::rowKey($rule->agentId, $rule->name),
                HilosDaemonCronTableRow::agentId => $rule->agentId,
                HilosDaemonCronTableRow::name => $rule->name,
                HilosDaemonCronTableRow::expression => $rule->expression,
                HilosDaemonCronTableRow::lastRunAt => $rule->lastRunAt,
                HilosDaemonCronTableRow::nextRunAt => $rule->nextRunAt,
                HilosDaemonCronTableRow::idleReason => $rule->agentId === null ? $cron->idleReason : null,
            ];
        }

        return $rows;
    }

    /**
     * @param ?string $agentId Agent owner, or null for the daemon
     * @param string $name Rule name
     * @return string Unambiguous, encoded owner/name key
     */
    private static function rowKey(?string $agentId, string $name): string
    {
        return rawurlencode((string) $agentId) . self::ROW_KEY_SEPARATOR . rawurlencode($name);
    }
}

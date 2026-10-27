<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonPictureRowChanges;
use Hilos\DaemonSection\DaemonPictureViewportTable;
use Hilos\Pages\Daemon\AbstractHilosDaemonCronPage;

/**
 * Cron rules of one node, projected only from the page worker's daemon picture mirror.
 *
 * A viewport without a node filter is empty. The mirror has no row change source, so
 * {@see AbstractHilosDaemonCronPage::onPictureChanged()} publishes picture mutations through the shared viewport classifier.
 */
final class HilosDaemonCronTable extends TableDefinition implements DaemonPictureViewportTable
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
     * @param ?ClusterDaemonPicture $before Previous immutable picture
     * @param TableQueryDTO $query Subject and viewer scope, independent of pagination and search membership
     * @return list<TableRowMutationDTO> Changes to the complete subject set
     * @throws TableRowKeyMissingException When a projected row carries no key
     */
    public function pictureMutations(?ClusterDaemonPicture $before, TableQueryDTO $query): array
    {
        return DaemonPictureRowChanges::between(
            $this->makeRows($this->collectRows($before, $query)),
            $this->makeRows($this->collectRows(ClusterDaemonPictureMirror::picture(), $query)),
        );
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
     * @throws TableSearchNotSupportedException When search arrives without its declared scope
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInMemory($this->collectRows(ClusterDaemonPictureMirror::picture(), $query), $rowKey, $query);
    }

    /**
     * @param TableQueryDTO $query Window query
     * @return TableSnapshotDTO Matching rules in the requested order, or picture order without a sort
     * @throws TableSearchNotSupportedException When search arrives without its declared scope
     * @throws TableSearchFieldUnknownException When a declared search field is absent
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->collectRows(ClusterDaemonPictureMirror::picture(), $query), $query);
    }

    /** Configures the cron row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosDaemonCronTableRow::class);
    }

    /** @return array<string, string> Search by rule name or agent instance id */
    protected function searchableFields(): array
    {
        return [
            HilosDaemonCronTableRow::name => HilosDaemonCronTableRow::name,
            HilosDaemonCronTableRow::agentId => HilosDaemonCronTableRow::agentId,
        ];
    }

    /** @return array<string, string> Order by rule name or next run epoch milliseconds */
    protected function sortableFields(): array
    {
        return [
            HilosDaemonCronTableRow::name => HilosDaemonCronTableRow::name,
            HilosDaemonCronTableRow::nextRunAt => HilosDaemonCronTableRow::nextRunAt,
        ];
    }

    /** @return bool Keep rules without a next run after known times in either direction */
    protected function inMemoryNullsLast(): bool
    {
        return true;
    }

    /**
     * @param ?ClusterDaemonPicture $picture Immutable source picture
     * @param TableQueryDTO $query Window filter naming one node
     * @return list<array<string, mixed>> Rules in the node's picture order
     */
    private function collectRows(?ClusterDaemonPicture $picture, TableQueryDTO $query): array
    {
        $node = $query->filter[self::FILTER_NODE] ?? null;
        if (!is_string($node) || trim($node) === '') {
            return [];
        }

        $cron = $picture?->node(trim($node))?->slot?->picture->cron;
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

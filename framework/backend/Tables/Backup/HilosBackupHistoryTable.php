<?php

declare(strict_types=1);

namespace Hilos\Tables\Backup;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\BackupChecksumState;
use Hilos\Backup\BackupProgress;
use Hilos\Backup\BackupShipState;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\RestoreMigrationDecision;
use Hilos\Backup\RestoreMigrationGuard;
use Hilos\Backup\Ship\BackupShipTarget;
use Hilos\Backup\Ship\BackupShipperFactory;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Hilos;
use Hilos\Pages\Backup\AbstractHilosBackupPage;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Item\BackupHistory;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\Runtime\View\Item\RestoreRuntime;
use Throwable;

/**
 * Framework backup list table: the stored backup index, with the run in flight as a bar.
 *
 * Read-only and live. Three framework-owned runtime sources feed it, no DB, and one of them
 * feeds no row: the {@see BackupHistory} index collection is one row per stored backup (files =
 * truth), the {@see RestoreRuntime} singleton decorates the one archive being replayed with its
 * restore's phase and outcome (HIL-276), and the {@see BackupRuntime} singleton declares the
 * progress bar above the table while a backup runs (HIL-820). The monopoly backup agent is the
 * sole writer of all three, so a completed backup fans out as an index-row create while the
 * runtime clears — the bar comes down and the finished row appears, in that order. Row
 * actions (create/delete/keep) are out of scope here; they land in HIL-333.
 *
 * A project activates the table by registering it under a table key and binding
 * that key to the backup page in {@see Hilos::PAGE_TABLES}; the runtime
 * sources are already registered by the project runtime context.
 */
class HilosBackupHistoryTable extends TableDefinition implements ViewportTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosBackups';

    /** Filter-map key: narrow the list to one backup scope. */
    public const string FILTER_SCOPE = 'scope';

    /** Filter-map key: the first day of the period, as `YYYY-MM-DD`; a backup taken that day is inside it. */
    public const string FILTER_FROM = 'from';

    /** Filter-map key: the last day of the period, as `YYYY-MM-DD`; a backup taken that day is inside it. */
    public const string FILTER_TO = 'to';

    /** Order key: scope first, the newest copy of each scope on top. */
    public const string ORDER_SCOPE_THEN_CREATED = 'scope_created';

    /** Order key: status first, the newest copy of each status on top. */
    public const string ORDER_STATUS_THEN_CREATED = 'status_created';

    /** Wire slot the row payload rides under; must match the frontend backup slot. */
    private const string ROW_SLOT = 'backup';

    /**
     * Key of the one bar this table ever shows: the backup run in flight.
     *
     * A constant rather than the id of the run, and the subsystem is what makes that safe: the
     * backup agent is monopolistic and refuses a second run while one is on, so the place above
     * this table is never contested. It has to be a constant, in fact — the frame that takes the
     * bar down is built after the runtime row was cleared, and there is no run left to name it by.
     */
    private const string PROGRESS_KEY = 'hilos-backup-run';

    /** Detail key beside the bar: the phase value the run is in, as the code names it. */
    public const string PROGRESS_DETAIL_PHASE = 'phase';

    /** Detail key beside the bar: seconds left, negative once the estimate is spent. */
    public const string PROGRESS_DETAIL_REMAINING_SECONDS = 'remainingSeconds';

    /** What the run's share is counted out of, the agent having already made it a percentage. */
    private const int PROGRESS_TOTAL = 100;

    /** Filters whose options this table counts: the scope alone, a period having no options to count. */
    private const array FACETED_FILTERS = [self::FILTER_SCOPE];

    /** Characters of an ISO-8601 instant that name its calendar day, `2026-09-17` out of `2026-09-17T03:00:00+00:00`. */
    private const int DAY_LENGTH = 10;

    /** Migration level this code expects; meaningless until {@see $codeMigrationIndexResolved}. */
    private ?int $codeMigrationIndex = null;

    /**
     * Whether the level above has been read off disk yet.
     *
     * A second flag and not a null check, because null is a real answer here - an installation
     * that lists no migrations - and caching it as "not read yet" would scan the directory again
     * for every row of every snapshot.
     */
    private bool $codeMigrationIndexResolved = false;

    /**
     * Declares how many rows the first window of the backup history carries.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 10;
    }

    /**
     * Declares the order the first window of the backup history runs in.
     *
     * @return ?TableSortOrderDTO First window ordered by createdAt descending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosBackupTableRow::createdAt, TableConstants::ORDER_DESC));
    }

    /**
     * Declares the one mass operation the backup list accepts: deleting the marked copies.
     *
     * The page judges each copy and hands it to the storage agent ({@see AbstractHilosBackupPage::onBulkRow()});
     * this declaration is only what lets the run be taken at all.
     *
     * @return list<string> Action names a bulk run over this table may carry
     */
    public function bulkActions(): array
    {
        return [HilosSignalConstants::BACKUP_BULK_DELETE];
    }

    /**
     * Builds a backup row mutation from a runtime source change.
     *
     * A change of the backup runtime singleton makes no row at all: the run it describes is
     * reported as this table's bar instead ({@see buildProgressForSourceEvent()}).
     *
     * @param SourceChange $change Runtime source change
     * @return ?TableRowMutationDTO Row mutation, or null when the change does not affect this table
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey === StateBackupHistory::RT_COLLECTION) {
            return $this->historyMutation($change);
        }

        if ($change->sourceKey === StateRestoreRuntime::RT_ITEM) {
            return $this->restoreMutation();
        }

        return null;
    }

    /**
     * Names the backup run in flight, which is the only work this table ever shows.
     *
     * A tab that opened in the middle of a run is told about it here and nowhere else: the run
     * writes its runtime row once a second, but a tab that arrived between two of those writes
     * would otherwise sit in front of an idle-looking list until the next one.
     *
     * @return list<TableProgressDTO> The run's bar while one is on, empty when the subsystem is idle
     * @throws InvalidArgumentException When the bar is built with a row key its place refuses
     */
    public function progressSnapshot(): array
    {
        $running = $this->runningBar();

        return $running === null ? [] : [$running];
    }

    /**
     * Turns a change of the backup runtime singleton into this table's bar.
     *
     * The row the backup agent writes is the whole channel: it says a run started, it carries the
     * share and the time left as they move, and its clearing is what takes the bar down. Nothing
     * else this table reads reports work.
     *
     * @param SourceChange $change Runtime source change
     * @return ?TableProgressDTO The run's bar, the frame that removes it, or null for another source
     * @throws InvalidArgumentException When the bar is built with a row key its place refuses
     */
    public function buildProgressForSourceEvent(SourceChange $change): ?TableProgressDTO
    {
        if ($change->sourceKey !== StateBackupRuntime::RT_ITEM) {
            return null;
        }

        return $this->runningBar() ?? new TableProgressDTO(
            TableProgressScope::Table,
            self::PROGRESS_KEY,
            null,
            0,
            null,
            true,
        );
    }

    /**
     * Serializes one backup row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Backup table row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Counts the options of the scope filter against the stored backup index.
     *
     * Each set is narrowed by {@see narrow()} and searched by the same in-memory filter a window
     * is, so the number beside an option is the total that window would report. The rows are
     * projected once for every set, and nothing is capped: they are in hand, and the count is exact.
     *
     * @param TableQueryDTO $query Window query whose search and filters describe the set, its search scoped
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts of the
     *     scope options that were asked about
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        $rows = $this->collectRows();

        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, array_flip(self::FACETED_FILTERS)),
            fn(TableQueryDTO $set): TableFacetCountDTO => new TableFacetCountDTO(
                $this->filterInMemory($this->narrow($rows, $set), $set)->totalCount,
                true,
            ),
        );
    }

    /**
     * Queries the stored backup index.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Backup table snapshot
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory($this->narrow($this->collectRows(), $query), $query);
    }

    /**
     * Declares the backup list's sortable columns, which here are the row payload keys themselves.
     *
     * The rows are ordered in PHP by the in-memory filter, where a field name is an array key and
     * no identifier is built out of it; the map is still declared, because it is the gate that keeps
     * a window from ordering by a name this table does not sort by.
     *
     * @return array<string, string> Wire row fields mapped to the payload keys they order by
     */
    protected function sortableFields(): array
    {
        return [
            HilosBackupTableRow::createdAt => HilosBackupTableRow::createdAt,
            HilosBackupTableRow::env => HilosBackupTableRow::env,
            HilosBackupTableRow::scope => HilosBackupTableRow::scope,
            HilosBackupTableRow::sizeBytes => HilosBackupTableRow::sizeBytes,
            HilosBackupTableRow::durationSeconds => HilosBackupTableRow::durationSeconds,
            HilosBackupTableRow::status => HilosBackupTableRow::status,
        ];
    }

    /**
     * Declares the two orders of more than one column the backup list offers in its menu.
     *
     * No index stands under either of them, and none is owed: the whole index is walked in memory
     * on every window, where an order costs nothing (`docs/agents/frontend/table-sort-orders.md`).
     * Both end on the newest copy first, which is the order the list opens in.
     *
     * @return array<string, TableSortOrderDTO> Order key => order it stands for
     */
    protected function sortOrders(): array
    {
        return [
            self::ORDER_SCOPE_THEN_CREATED => TableSortOrderDTO::of(
                new TableSortDTO(HilosBackupTableRow::scope),
                new TableSortDTO(HilosBackupTableRow::createdAt, TableConstants::ORDER_DESC),
            ),
            self::ORDER_STATUS_THEN_CREATED => TableSortOrderDTO::of(
                new TableSortDTO(HilosBackupTableRow::status),
                new TableSortDTO(HilosBackupTableRow::createdAt, TableConstants::ORDER_DESC),
            ),
        ];
    }

    /**
     * Declares what a backup row is searched by: where it was taken, what of, and how it ended.
     *
     * @return array<string, string> Searched fields mapped to themselves, these rows being searched in memory
     */
    protected function searchableFields(): array
    {
        return [
            HilosBackupTableRow::env => HilosBackupTableRow::env,
            HilosBackupTableRow::scope => HilosBackupTableRow::scope,
            HilosBackupTableRow::status => HilosBackupTableRow::status,
            HilosBackupTableRow::failureReason => HilosBackupTableRow::failureReason,
        ];
    }

    /**
     * Configures the row shape used by the backup table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosBackupTableRow::class);
    }

    /**
     * Projects the whole stored backup index into row payloads.
     *
     * @return list<array<string, mixed>> Row payloads, in the order the index hands them over
     */
    private function collectRows(): array
    {
        $rows = [];
        // The index is resolved before it is walked: an unmounted one leaves an empty set,
        // where iterating null would only have added a warning to that.
        $histories = $this->histories();
        if ($histories !== null) {
            foreach ($histories as $history) {
                $rows[] = $this->rowFromHistory($history)->toArray();
            }
        }

        return $rows;
    }

    /**
     * Applies the scope filter and the period this list answers to.
     *
     * The period is compared by calendar day, both ends inclusive, the way the delivery journal
     * reads the same two keys: a backup taken on the last day of the period is inside it.
     *
     * @param list<array<string, mixed>> $rows Rows of the whole index
     * @param TableQueryDTO $query Window query
     * @return list<array<string, mixed>> Rows the window asked for
     */
    private function narrow(array $rows, TableQueryDTO $query): array
    {
        $scope = self::filterString($query, self::FILTER_SCOPE);
        if ($scope !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row[HilosBackupTableRow::scope] === $scope,
            ));
        }

        $from = self::filterDay($query, self::FILTER_FROM);
        if ($from !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => self::day((string) $row[HilosBackupTableRow::createdAt]) >= $from,
            ));
        }

        $to = self::filterDay($query, self::FILTER_TO);
        if ($to !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => self::day((string) $row[HilosBackupTableRow::createdAt]) <= $to,
            ));
        }

        return $rows;
    }

    /**
     * Reads one string filter value from the open filter map.
     *
     * @param TableQueryDTO $query Window query
     * @param string $key Filter key
     * @return ?string Trimmed value, or null when the window filters on nothing here
     */
    private static function filterString(TableQueryDTO $query, string $key): ?string
    {
        $value = $query->filter[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Reads one bound of the period as the calendar day it names.
     *
     * @param TableQueryDTO $query Window query
     * @param string $key Filter key of the bound
     * @return ?string The day as `YYYY-MM-DD`, or null when the window sets no such bound
     */
    private static function filterDay(TableQueryDTO $query, string $key): ?string
    {
        $value = self::filterString($query, $key);

        return $value === null ? null : self::day($value);
    }

    /**
     * Cuts an instant down to the calendar day it falls on.
     *
     * A string cut rather than a parse: the index writes ISO-8601 and the filter writes a bare
     * day, so the first ten characters of both are the same `YYYY-MM-DD` and compare as text.
     *
     * @param string $instant ISO-8601 instant or bare day
     * @return string The day as `YYYY-MM-DD`
     */
    private static function day(string $instant): string
    {
        return substr($instant, 0, self::DAY_LENGTH);
    }

    /**
     * Builds the row mutation for a backup index-collection change.
     *
     * @param SourceChange $change Backup index source change
     * @return TableRowMutationDTO Row create/update/delete mutation
     */
    private function historyMutation(SourceChange $change): TableRowMutationDTO
    {
        $id = $change->sourceId;
        $histories = $this->histories();
        $history = $histories === null ? null : $histories[$id];
        if ($history === null) {
            return $this->mutation(TableMutationType::Delete, $id);
        }

        return $this->mutation(
            $change->mutationType === TableMutationType::Delete ? TableMutationType::Update : $change->mutationType,
            $id,
            $this->rowFromHistory($history),
        );
    }

    /**
     * Builds the row mutation for a change of the restore runtime singleton (HIL-276).
     *
     * A restore is about one archive, so it moves one row: the archive being replayed grows the
     * phase while the run is on and keeps its outcome afterwards. An ordinary update carries it,
     * and reaches the tab at once for that reason - a field written onto a row already in the
     * window is applied where it stands, without waiting for anything.
     *
     * The row is left alone when the restore names an archive this index does not carry, which is
     * what an idle row and a restore of a since-deleted archive both look like.
     *
     * @return ?TableRowMutationDTO Row update for the restored archive, or null when there is none to update
     */
    private function restoreMutation(): ?TableRowMutationDTO
    {
        $restoredId = $this->restoreRuntimeView()?->backupId;
        $histories = $this->histories();
        $history = $restoredId === null || $histories === null ? null : $histories[$restoredId];
        if ($history === null) {
            return null;
        }

        return $this->mutation(
            TableMutationType::Update,
            $restoredId,
            $this->rowFromHistory($history),
        );
    }

    /**
     * Projects a stored backup index row into a table row.
     *
     * @param BackupHistory $history Stored backup index row
     * @return HilosBackupTableRow Backup table row
     */
    private function rowFromHistory(BackupHistory $history): HilosBackupTableRow
    {
        $id = $history->getId();
        // Only the archive the restore names carries restore fields: the runtime row is a
        // singleton about the last run, and copying it onto every row would tell the whole list
        // that it had been restored.
        $restore = $this->restoreRuntimeView();
        $restored = $restore !== null && $restore->backupId === $id ? $restore : null;
        // The same verdict the restore action re-derives, computed here from the levels the index
        // row already carries, so the list answers "restorable?" without opening a single sidecar.
        $migration = RestoreMigrationGuard::decide($history->connections, $this->codeMigrationIndex());
        $refused = $migration->decision === RestoreMigrationDecision::REFUSE;

        return new HilosBackupTableRow(
            rowKey: $id,
            createdAt: $history->createdAt,
            env: $history->env,
            scope: $history->scope,
            sizeBytes: $history->sizeBytes,
            durationSeconds: $history->durationSeconds,
            keep: $history->keep,
            status: $history->status,
            finished: $history->status === BackupStatus::SUCCESS->value ? true : null,
            failureReason: $history->failureReason,
            checksumState: BackupChecksumState::fromRecord($history->sha256, $history->verifyOutcome),
            verifiedAt: $history->verifiedAt,
            shipState: BackupShipState::fromRecord(
                $this->shippingConfigured(),
                $history->status,
                $history->shippedAt,
                $history->shipOutcome,
            ),
            shippedAt: $history->shippedAt,
            shipError: $history->shipError,
            restorePhase: $restored?->phase,
            restoreOutcome: $restored?->outcome,
            restoreFinishedAt: $restored?->finishedAt,
            restoreFailureReason: $restored?->failureReason,
            restoreDatabaseTouched: self::restoreDamagedDatabase($restored),
            restoreMigrationDecision: $migration->decision->value,
            restoreMigrationBehind: $migration->migrationsBehind(),
            // A refusal explains itself; anything else is worded gap by gap, and an archive with
            // nothing to say about its levels says nothing at all.
            restoreMigrationNotice: $refused
                ? $migration->reason
                : (implode("\n", $migration->describeGaps()) ?: null),
            holderNode: $history->reachable ? null : $history->nodeId,
        );
    }

    /**
     * Whether the restore this row carries left the database half-replaced.
     *
     * Not the runtime flag as it stands: the run reaches its first destructive step on the way
     * to succeeding too ({@see BackupAgent::restoreTouchedDatabase()} reads one exit code as
     * "intact" and everything else as "assume touched"), so the raw flag is true after every
     * successful restore. On the row it answers a different question - whether the archive's
     * restore left damage behind - and that question only exists for a run that failed. A
     * success replaced the database on purpose and completely, which is not damage but the
     * point of the operation.
     *
     * @param ?RestoreRuntime $restored Restore runtime row of THIS archive, or null when it was never restored
     * @return bool True when a failed restore of this archive had begun replacing the database
     */
    private static function restoreDamagedDatabase(?RestoreRuntime $restored): bool
    {
        return $restored?->outcome === BackupStatus::ERROR->value && $restored->databaseTouched;
    }

    /**
     * Builds the bar of the backup run in flight, or null when the subsystem is idle.
     *
     * Both figures arrive already counted ({@see BackupProgress}), so the bar is a plain reading
     * of the runtime row: the share is what the agent wrote, and the absent total is what says a
     * run has no estimate at all - the difference between a bar standing at a share and one
     * running its stripes. The ceiling of 99 is the agent's too, so a run still going never draws
     * a full bar.
     *
     * @return ?TableProgressDTO Bar above the table while a backup runs, or null when none does
     */
    private function runningBar(): ?TableProgressDTO
    {
        $runtime = $this->runtimeView();
        if ($runtime === null || !$runtime->running) {
            return null;
        }

        $detail = [];
        if ($runtime->phase !== null) {
            $detail[self::PROGRESS_DETAIL_PHASE] = $runtime->phase;
        }
        if ($runtime->remainingSeconds !== null) {
            $detail[self::PROGRESS_DETAIL_REMAINING_SECONDS] = $runtime->remainingSeconds;
        }

        return new TableProgressDTO(
            TableProgressScope::Table,
            self::PROGRESS_KEY,
            null,
            $runtime->percent ?? 0,
            $runtime->percent === null ? null : self::PROGRESS_TOTAL,
            false,
            $detail,
        );
    }

    /**
     * Whether this installation copies backups off the machine at all.
     *
     * Asked per build rather than stored, and answered by the same two questions the agent asks
     * before it ships anything - does the destination parse, and is there a driver that serves
     * it. A value nothing can ship to is the same to a reader as no value at all, and asking only
     * the first question would leave every row saying "pending" for a copy that is never coming:
     * an ssh destination without a pinned receiver parses perfectly and ships nothing
     * ({@see BackupShipperFactory::fromTarget()}).
     *
     * @return bool True when a usable destination is configured
     */
    private function shippingConfigured(): bool
    {
        try {
            $target = BackupShipTarget::fromEnv();

            return $target !== null && BackupShipperFactory::fromTarget($target) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Resolves the backup index runtime collection, or null when unavailable.
     *
     * A seam the framework reads from the runtime facade; tests bind in-memory state.
     * The `??` is what makes an unmounted index a null rather than a throw: it asks the
     * runtime context's `__isset()` first, where a bare read would raise
     * RtCollectionNotFoundException.
     *
     * @return ?BackupHistories Backup index collection, or null when the BACKUP feature is inactive
     */
    protected function histories(): ?BackupHistories
    {
        return Hilos::$rt?->hilosBackupHistories ?? null;
    }

    /**
     * Resolves the backup runtime singleton, or null when unavailable/idle.
     *
     * A seam the framework reads from the runtime facade; tests bind in-memory state.
     *
     * @return ?BackupRuntime Backup runtime singleton view, or null
     */
    protected function runtimeView(): ?BackupRuntime
    {
        $runtime = Hilos::$rt?->hilosBackupRuntime;

        return $runtime instanceof BackupRuntime ? $runtime : null;
    }

    /**
     * Resolves the restore runtime singleton, or null when unavailable.
     *
     * The same seam as {@see runtimeView()}, over the row the restore path writes: the table
     * only reads it, and the monopoly backup agent stays its single writer.
     *
     * @return ?RestoreRuntime Restore runtime singleton, or null
     */
    protected function restoreRuntimeView(): ?RestoreRuntime
    {
        $restore = Hilos::$rt?->hilosRestoreRuntime;

        return $restore instanceof RestoreRuntime ? $restore : null;
    }

    /**
     * Reads the migration level this code expects, once per table instance.
     *
     * The only disk read on the row-building path: {@see RestoreMigrationGuard::codeMigrationIndex()}
     * scans the migration directory, and a snapshot asks for it once per row. Caching it here is
     * safe because the answer is a property of the running code - the files are read by a process
     * that would have to restart for them to change.
     *
     * @return ?int Migration level this code expects; null when it lists no migrations
     */
    private function codeMigrationIndex(): ?int
    {
        if (!$this->codeMigrationIndexResolved) {
            $this->codeMigrationIndex = RestoreMigrationGuard::codeMigrationIndex();
            $this->codeMigrationIndexResolved = true;
        }

        return $this->codeMigrationIndex;
    }
}

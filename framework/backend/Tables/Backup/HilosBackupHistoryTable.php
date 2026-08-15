<?php

declare(strict_types=1);

namespace Hilos\Tables\Backup;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\BackupChecksumState;
use Hilos\Backup\BackupStatus;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Item\BackupHistory;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\Runtime\View\Item\RestoreRuntime;
use Throwable;

/**
 * Framework backup list table: the stored backup index plus the in-progress row.
 *
 * Read-only and live. Rows come from three framework-owned runtime sources, no DB:
 * the {@see BackupHistory} index collection (one row per stored backup, files =
 * truth), the {@see BackupRuntime} singleton (the single in-progress row while
 * a backup runs), and the {@see RestoreRuntime} singleton, which decorates the one
 * archive being replayed with its restore's phase and outcome (HIL-276). The
 * monopoly backup agent is the sole writer of all three, so a
 * completed backup fans out as an index-row create while the runtime clears — the
 * in-progress row is deleted and the finished row appears in its place. Row
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

    /** Wire slot the row payload rides under; must match the frontend backup slot. */
    private const string ROW_SLOT = 'backup';

    /** Synthetic status of the in-progress row (a stored backup carries success/error). */
    private const string RUNNING_STATUS = 'running';

    /**
     * Builds a backup row mutation from a runtime source change.
     *
     * @param SourceChange $change Runtime source change
     * @return ?TableRowMutationDTO Row mutation, or null when the change does not affect this table
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey === StateBackupHistory::RT_COLLECTION) {
            return $this->historyMutation($change);
        }

        if ($change->sourceKey === StateBackupRuntime::RT_ITEM) {
            return $this->runtimeMutation();
        }

        if ($change->sourceKey === StateRestoreRuntime::RT_ITEM) {
            return $this->restoreMutation();
        }

        return null;
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
     * Queries the merged backup rows: the stored index plus any in-progress row.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Backup table snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = [];
        // The index is resolved before it is walked: an unmounted one leaves the snapshot to the
        // in-progress row alone, where iterating null would only have added a warning to that.
        $histories = $this->histories();
        if ($histories !== null) {
            foreach ($histories as $history) {
                $rows[] = $this->rowFromHistory($history)->toArray();
            }
        }

        $running = $this->runningRow();
        if ($running !== null) {
            $rows[] = $running->toArray();
        }

        return InMemoryTableFilter::apply($rows, $query);
    }

    /**
     * Configures the row shape used by the backup table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosBackupTableRow::class);
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
     * Builds the row mutation for a backup runtime-singleton change.
     *
     * A started backup creates the single in-progress row; a finished/idle runtime
     * deletes it, leaving the stored index row (fanned out separately) in its place.
     *
     * @return TableRowMutationDTO In-progress row create or delete mutation
     */
    private function runtimeMutation(): TableRowMutationDTO
    {
        // The in-progress row is a status the table shows about work, not content the reader is
        // studying: gating it behind Apply would strand "In progress" on screen long after the
        // run ended. Both its arrival and its removal are declared live.
        $running = $this->runningRow();
        if ($running === null) {
            return $this->mutation(
                TableMutationType::Delete,
                HilosBackupTableRow::RUNNING_ROW_KEY,
                live: true,
            );
        }

        return $this->mutation(
            TableMutationType::Create,
            HilosBackupTableRow::RUNNING_ROW_KEY,
            $running,
            live: true,
        );
    }

    /**
     * Builds the row mutation for a change of the restore runtime singleton (HIL-276).
     *
     * A restore is about one archive, so it moves one row: the archive being replayed grows the
     * live phase while the run is on and keeps its outcome afterwards. Declared live for the same
     * reason the in-progress row is - this is status about work, not content being read, and a
     * phase held behind Apply would still say "importing" long after the run ended.
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
            live: true,
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
            restorePhase: $restored?->phase,
            restoreOutcome: $restored?->outcome,
            restoreFinishedAt: $restored?->finishedAt,
            restoreFailureReason: $restored?->failureReason,
            restoreDatabaseTouched: self::restoreDamagedDatabase($restored),
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
     * Builds the single in-progress backup row, or null when no backup is running.
     *
     * @return ?HilosBackupTableRow In-progress backup row, or null when idle
     */
    private function runningRow(): ?HilosBackupTableRow
    {
        // A run records its start time together with the running flag, so the second
        // half of the guard is dead — and a row that cannot say when it started is
        // not a row the journal can order.
        $runtime = $this->runtimeView();
        if ($runtime === null || !$runtime->running || $runtime->startedAt === null) {
            return null;
        }

        return new HilosBackupTableRow(
            rowKey: HilosBackupTableRow::RUNNING_ROW_KEY,
            createdAt: $runtime->startedAt,
            env: $this->currentEnv(),
            scope: $runtime->scope,
            sizeBytes: 0,
            durationSeconds: 0,
            keep: false,
            status: self::RUNNING_STATUS,
            finished: false,
            failureReason: null,
            // A running backup has no archive yet, so it has nothing to checksum.
            checksumState: BackupChecksumState::NONE,
            verifiedAt: null,
        );
    }

    /**
     * Reads the current application environment for the in-progress row.
     *
     * The in-progress backup runs in the current environment, which the runtime
     * singleton does not carry. APP_ENV is always cataloged and set, so a failure
     * is not expected; it degrades to an unnamed ENV cell rather than dropping the row.
     *
     * @return ?string Current application environment, or null when unreadable
     */
    private function currentEnv(): ?string
    {
        try {
            return Hilos::$env?->string(EnvConstants::APP_ENV);
        } catch (Throwable) {
            return null;
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
}

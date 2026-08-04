<?php

declare(strict_types=1);

namespace Hilos\Tables\Backup;

use Hilos\Backup\BackupChecksumState;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Runtime\State\Item\BackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime;
use Throwable;

/**
 * Framework backup list table: the stored backup index plus the in-progress row.
 *
 * Read-only and live. Rows come from two framework-owned runtime sources, no DB:
 * the {@see BackupHistory} index collection (one row per stored backup, files =
 * truth) and the {@see BackupRuntime} singleton (the single in-progress row while
 * a backup runs). The monopoly backup agent is the sole writer of both, so a
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
        if ($change->sourceKey === BackupHistory::RT_COLLECTION) {
            return $this->historyMutation($change);
        }

        if ($change->sourceKey === BackupRuntime::RT_ITEM) {
            return $this->runtimeMutation();
        }

        return null;
    }

    /**
     * Serializes one backup row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Backup table row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->getRowKey() ?? '',
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
        foreach ($this->histories() as $history) {
            $rows[] = $this->rowFromHistory($history)->toArray();
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
        $history = $this->histories()?->get($id);
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
     * Projects a stored backup index row into a table row.
     *
     * @param BackupHistory $history Stored backup index row
     * @return HilosBackupTableRow Backup table row
     */
    private function rowFromHistory(BackupHistory $history): HilosBackupTableRow
    {
        return new HilosBackupTableRow(
            rowKey: $history->getId(),
            createdAt: $history->createdAt,
            env: $history->env,
            scope: $history->scope,
            sizeBytes: $history->sizeBytes,
            durationSeconds: $history->durationSeconds,
            keep: $history->keep,
            status: $history->status,
            finished: $history->status === BackupStatus::SUCCESS->value ? true : null,
            failureReason: $history->failureReason,
            checksumState: self::checksumStateOf($history),
            verifiedAt: $history->verifiedAt,
        );
    }

    /**
     * Derives the checksum state a list row shows from what the index row carries.
     *
     * No digest wins over everything else: a row that was never hashed has nothing to have
     * been verified, whatever else it carries. Any other stored outcome than ok/mismatch (none
     * is ever written today) degrades to "present" - a digest exists, and nothing trustworthy
     * is known about a check of it.
     *
     * @param BackupHistory $history Stored backup index row
     * @return BackupChecksumState Checksum state for the row
     */
    private static function checksumStateOf(BackupHistory $history): BackupChecksumState
    {
        if ($history->sha256 === null) {
            return BackupChecksumState::NONE;
        }

        return match (BackupVerifyOutcome::fromString($history->verifyOutcome)) {
            BackupVerifyOutcome::OK => BackupChecksumState::VERIFIED,
            BackupVerifyOutcome::MISMATCH => BackupChecksumState::MISMATCH,
            default => BackupChecksumState::PRESENT,
        };
    }

    /**
     * Builds the single in-progress backup row, or null when no backup is running.
     *
     * @return ?HilosBackupTableRow In-progress backup row, or null when idle
     */
    private function runningRow(): ?HilosBackupTableRow
    {
        $runtime = $this->runtimeState();
        if ($runtime === null || !$runtime->running) {
            return null;
        }

        return new HilosBackupTableRow(
            rowKey: HilosBackupTableRow::RUNNING_ROW_KEY,
            createdAt: $runtime->startedAt ?? '',
            env: $this->currentEnv(),
            scope: $runtime->scope ?? '',
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
     * is not expected; it degrades to an empty ENV cell rather than dropping the row.
     *
     * @return string Current application environment, or an empty string when unreadable
     */
    private function currentEnv(): string
    {
        try {
            return Hilos::$env?->string(EnvConstants::APP_ENV) ?? '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Resolves the backup index runtime collection, or null when unavailable.
     *
     * A seam the framework reads from the runtime facade; tests bind in-memory state.
     *
     * @return ?BackupHistories Backup index collection, or null
     */
    protected function histories(): ?BackupHistories
    {
        $collection = Hilos::$rt?->getStateCollection(BackupHistory::RT_COLLECTION);

        return $collection instanceof BackupHistories ? $collection : null;
    }

    /**
     * Resolves the backup runtime singleton, or null when unavailable/idle.
     *
     * A seam the framework reads from the runtime facade; tests bind in-memory state.
     *
     * @return ?BackupRuntime Backup runtime singleton, or null
     */
    protected function runtimeState(): ?BackupRuntime
    {
        $runtime = Hilos::$rt?->getStateItem(BackupRuntime::RT_ITEM);

        return $runtime instanceof BackupRuntime ? $runtime : null;
    }
}

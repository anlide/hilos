<?php

declare(strict_types=1);

namespace Hilos\Tables\Backup;

use Hilos\Backup\BackupChecksumState;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Runtime\View\Item\BackupHistory;

/**
 * Backend row payload for the framework backup list table.
 *
 * A row is either a stored backup (projected from a {@see BackupHistory} index
 * row) or the single in-progress backup (projected from the backup runtime
 * singleton). The two are told apart by {@see finished}: a stored backup carries
 * true (completed) or null (a recorded failure), and the in-progress row carries
 * false so the frontend renders its live progress indicator. The in-progress row
 * uses the fixed {@see RUNNING_ROW_KEY} so it never collides with a stored
 * backup's id and is deleted cleanly when the backup finishes.
 */
final class HilosBackupTableRow extends AbstractTableRow
{
    /** Stable row key of the single in-progress backup row. */
    public const string RUNNING_ROW_KEY = '__running__';

    /**
     * Payload key of the row identity.
     *
     * It rides the row fragment's `rowKey`, never a field inside the slot: a slot payload
     * carrying `id` is ingested by the frontend normalizer as an entity fragment and replaced
     * with a reference, which would strip every other field off this row
     * ({@see AbstractTableRow}).
     */
    public const string rowKey = 'rowKey';

    public const string createdAt = 'createdAt';
    public const string env = 'env';
    public const string scope = 'scope';
    public const string sizeBytes = 'sizeBytes';
    public const string durationSeconds = 'durationSeconds';
    public const string keep = 'keep';
    public const string status = 'status';
    public const string finished = 'finished';
    public const string failureReason = 'failureReason';
    public const string checksumState = 'checksumState';
    public const string verifiedAt = 'verifiedAt';
    public const string restorePhase = 'restorePhase';
    public const string restoreOutcome = 'restoreOutcome';
    public const string restoreFinishedAt = 'restoreFinishedAt';
    public const string restoreFailureReason = 'restoreFailureReason';
    public const string restoreDatabaseTouched = 'restoreDatabaseTouched';
    public const string progressPhase = 'progressPhase';
    public const string progressPhaseStartedAt = 'progressPhaseStartedAt';
    public const string progressEstimatedSeconds = 'progressEstimatedSeconds';

    /**
     * @param string $rowKey Stable table row key (backup id, or RUNNING_ROW_KEY)
     * @param string $createdAt ISO-8601 creation/start timestamp
     * @param ?string $env Application environment the backup was taken in; null when the record names none
     * @param ?string $scope Backup scope value; null when the record names none
     * @param int $sizeBytes Archive size in bytes (0 while in progress)
     * @param int $durationSeconds Capture duration in seconds (0 while in progress)
     * @param bool $keep Whether the backup is pinned out of rotation
     * @param string $status Status value
     * @param ?bool $finished true completed, false in progress, null failed/incomplete
     * @param ?string $failureReason Why the run failed (error rows only); null otherwise
     * @param BackupChecksumState $checksumState Whether the backup carries a digest and how it last verified;
     *     the digest itself never reaches the browser
     * @param ?string $verifiedAt ISO-8601 instant of the last verification; null means never verified
     * @param ?string $restorePhase Phase value of the restore of THIS archive; null when it was never restored
     * @param ?string $restoreOutcome Terminal status value of that restore; null while it runs or was never run
     * @param ?string $restoreFinishedAt ISO-8601 instant that restore ended; null while it runs or was never run
     * @param ?string $restoreFailureReason Why that restore failed; null when it succeeded or was never run
     * @param bool $restoreDatabaseTouched Whether the failed restore of this archive had begun replacing the
     *     database; false for a run that never started one
     * @param ?string $progressPhase Phase the run in progress is in; null on a stored archive, which has no run
     * @param ?string $progressPhaseStartedAt ISO-8601 instant that phase began; null when there is no phase
     * @param ?int $progressEstimatedSeconds How long the run in progress is expected to take; null without history
     */
    public function __construct(
        public string $rowKey,
        public string $createdAt,
        public ?string $env,
        public ?string $scope,
        public int $sizeBytes,
        public int $durationSeconds,
        public bool $keep,
        public string $status,
        public ?bool $finished,
        public ?string $failureReason,
        public BackupChecksumState $checksumState,
        public ?string $verifiedAt,
        // The restore of an archive is a property of the run, not of the archive, and only one
        // archive is ever the restored one - so every other row says "no restore" by omission
        // rather than by five nulls written out at each of its construction sites.
        public ?string $restorePhase = null,
        public ?string $restoreOutcome = null,
        public ?string $restoreFinishedAt = null,
        public ?string $restoreFailureReason = null,
        public bool $restoreDatabaseTouched = false,
        // The three anchors a progress bar is drawn from, and only the in-progress row carries
        // them: the percentage and the time left are computed by the browser, which is why the
        // row ships the phase and its instants rather than a number that would be stale on
        // arrival and would need a table update per second to stay fresh.
        public ?string $progressPhase = null,
        public ?string $progressPhaseStartedAt = null,
        public ?int $progressEstimatedSeconds = null,
    ) {
    }

    /**
     * Returns the stable table row key.
     *
     * @return string Row key
     */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /**
     * Serializes the row to the backup table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            // The key rides the payload under a name the normalizer ignores; `id` would make
            // the whole slot look like an entity fragment on the frontend.
            self::rowKey => $this->rowKey,
            self::createdAt => $this->createdAt,
            self::env => $this->env,
            self::scope => $this->scope,
            self::sizeBytes => $this->sizeBytes,
            self::durationSeconds => $this->durationSeconds,
            self::keep => $this->keep,
            self::status => $this->status,
            self::finished => $this->finished,
            self::failureReason => $this->failureReason,
            self::checksumState => $this->checksumState->value,
            self::verifiedAt => $this->verifiedAt,
            self::restorePhase => $this->restorePhase,
            self::restoreOutcome => $this->restoreOutcome,
            self::restoreFinishedAt => $this->restoreFinishedAt,
            self::restoreFailureReason => $this->restoreFailureReason,
            self::restoreDatabaseTouched => $this->restoreDatabaseTouched,
            self::progressPhase => $this->progressPhase,
            self::progressPhaseStartedAt => $this->progressPhaseStartedAt,
            self::progressEstimatedSeconds => $this->progressEstimatedSeconds,
        ];
    }

    /**
     * Builds a backup row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed backup table row
     * @throws InvalidFormatException When the payload is missing a field the row is built from
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            createdAt: self::requireString($data, self::createdAt),
            env: self::optionalString($data, self::env),
            scope: self::optionalString($data, self::scope),
            sizeBytes: self::requireInt($data, self::sizeBytes),
            durationSeconds: self::requireInt($data, self::durationSeconds),
            keep: self::requireBool($data, self::keep),
            status: self::requireString($data, self::status),
            finished: array_key_exists(self::finished, $data) ? self::toTriState($data[self::finished]) : null,
            failureReason: self::optionalString($data, self::failureReason),
            // An unknown or absent state reads back as "no digest": a row that cannot say it was
            // checked must not claim it was.
            checksumState: BackupChecksumState::fromString(
                isset($data[self::checksumState]) ? (string) $data[self::checksumState] : null,
            ) ?? BackupChecksumState::NONE,
            verifiedAt: self::optionalString($data, self::verifiedAt),
            restorePhase: self::optionalString($data, self::restorePhase),
            restoreOutcome: self::optionalString($data, self::restoreOutcome),
            restoreFinishedAt: self::optionalString($data, self::restoreFinishedAt),
            restoreFailureReason: self::optionalString($data, self::restoreFailureReason),
            // A row that says nothing about a restore says nothing about its damage either, and
            // "not touched" is the only reading that does not invent reassurance.
            restoreDatabaseTouched: (bool)($data[self::restoreDatabaseTouched] ?? false),
            progressPhase: self::optionalString($data, self::progressPhase),
            progressPhaseStartedAt: self::optionalString($data, self::progressPhaseStartedAt),
            progressEstimatedSeconds: self::optionalInt($data, self::progressEstimatedSeconds),
        );
    }

    /**
     * Normalizes a raw finished value to the tri-state used by the frontend.
     *
     * @param mixed $value Raw finished value
     * @return ?bool true, false, or null
     */
    private static function toTriState(mixed $value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }
}

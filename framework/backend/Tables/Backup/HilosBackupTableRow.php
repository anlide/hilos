<?php

declare(strict_types=1);

namespace Hilos\Tables\Backup;

use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Runtime\State\Item\BackupHistory;

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
     * ({@see \Hilos\Core\Table\Row\AbstractTableRow}).
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

    /**
     * @param string $rowKey Stable table row key (backup id, or RUNNING_ROW_KEY)
     * @param string $createdAt ISO-8601 creation/start timestamp
     * @param string $env Application environment the backup was taken in
     * @param string $scope Backup scope value
     * @param int $sizeBytes Archive size in bytes (0 while in progress)
     * @param int $durationSeconds Capture duration in seconds (0 while in progress)
     * @param bool $keep Whether the backup is pinned out of rotation
     * @param string $status Status value
     * @param ?bool $finished true completed, false in progress, null failed/incomplete
     */
    public function __construct(
        public string $rowKey,
        public string $createdAt,
        public string $env,
        public string $scope,
        public int $sizeBytes,
        public int $durationSeconds,
        public bool $keep,
        public string $status,
        public ?bool $finished,
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
        ];
    }

    /**
     * Builds a backup row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed backup table row
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: (string) ($data[self::rowKey] ?? ''),
            createdAt: (string) ($data[self::createdAt] ?? ''),
            env: (string) ($data[self::env] ?? ''),
            scope: (string) ($data[self::scope] ?? ''),
            sizeBytes: (int) ($data[self::sizeBytes] ?? 0),
            durationSeconds: (int) ($data[self::durationSeconds] ?? 0),
            keep: (bool) ($data[self::keep] ?? false),
            status: (string) ($data[self::status] ?? ''),
            finished: array_key_exists(self::finished, $data) ? self::toTriState($data[self::finished]) : null,
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

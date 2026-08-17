<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Runtime\State\Collection\BackupHistories;

/**
 * BackupHistory - one runtime index row for a stored backup.
 *
 * Framework-owned runtime state: the project registers the backing
 * {@see BackupHistories} collection under
 * {@see RT_COLLECTION}, and the monopoly backup agent is its single writer.
 * Rows mirror the {@see BackupMetadata} sidecar (files=truth, RT=index). The
 * browser view/representation lands in HIL-278.
 */
final class BackupHistory extends RtState
{
    /** Runtime collection key registered by the project and used for RT sync. */
    public const string RT_COLLECTION = 'hilosBackupHistories';

    public const string id = 'id';
    public const string createdAt = 'createdAt';
    public const string env = 'env';
    public const string scope = 'scope';
    public const string connections = 'connections';
    public const string sizeBytes = 'sizeBytes';
    public const string durationSeconds = 'durationSeconds';
    public const string keep = 'keep';
    public const string status = 'status';
    public const string failureReason = 'failureReason';
    public const string dumpBytes = 'dumpBytes';
    public const string sha256 = 'sha256';
    public const string verifiedAt = 'verifiedAt';
    public const string verifyOutcome = 'verifyOutcome';
    public const string restoredAt = 'restoredAt';
    public const string restoreDurationSeconds = 'restoreDurationSeconds';
    public const string shippedAt = 'shippedAt';
    public const string shipOutcome = 'shipOutcome';
    public const string shipError = 'shipError';

    /** Backup id (also the archive/sidecar base name). */
    private(set) string $id = '';

    /** ISO-8601 creation timestamp. */
    public string $createdAt = '';

    /** Application environment the backup was taken in. */
    public string $env = '';

    /** Scope value ({@see BackupScope}). */
    public ?string $scope = null;

    /** @var list<BackupConnectionMeta> Connections captured in the backup. */
    public array $connections = [];

    /** Archive size in bytes. */
    public int $sizeBytes = 0;

    /** Wall-clock capture duration in seconds. */
    public int $durationSeconds = 0;

    /** Retention pin: true excludes the backup from rotation. */
    public bool $keep = false;

    /** Status value ({@see BackupStatus}). */
    public string $status = '';

    /** Why the run failed (error rows only); null for success rows and legacy sidecars. */
    public ?string $failureReason = null;

    /**
     * Uncompressed dump volume in bytes; 0 for error rows and legacy sidecars. The field must
     * reach runtime because the space guard estimates a run's size from the index, not from the
     * sidecars on disk. Not carried into the browser table: the operator sees the archive size.
     */
    public int $dumpBytes = 0;

    /** Archive digest from the sidecar; null for error rows and backups written before digests. */
    public ?string $sha256 = null;

    /** ISO-8601 instant of the last verification; null means never verified. */
    public ?string $verifiedAt = null;

    /** Outcome value of that verification ({@see BackupVerifyOutcome}); null if never verified. */
    public ?string $verifyOutcome = null;

    /** ISO-8601 instant this archive was last restored from; null means never restored. */
    public ?string $restoredAt = null;

    /**
     * How long that restore took in seconds; 0 means "no data". Restores have no history of their
     * own - the runtime row of a restore holds only the run in flight - so the archive keeps it,
     * and the estimate for the next restore is read from these rows.
     */
    public int $restoreDurationSeconds = 0;

    /** ISO-8601 instant this backup was last copied off the machine; null means never. */
    public ?string $shippedAt = null;

    /** Outcome value of that copy ({@see BackupShipOutcome}); null if never attempted. */
    public ?string $shipOutcome = null;

    /** Why the last copy attempt failed; null when none has. */
    public ?string $shipError = null;

    /**
     * Builds a history row from a scanned sidecar's metadata.
     *
     * @param BackupMetadata $metadata Sidecar metadata
     * @return static Fresh history row
     */
    public static function fromMetadata(BackupMetadata $metadata): static
    {
        $instance = new static();
        $instance->id = $metadata->id;
        $instance->createdAt = $metadata->createdAt;
        $instance->env = $metadata->env;
        $instance->scope = $metadata->scope->value;
        $instance->connections = $metadata->connections;
        $instance->sizeBytes = $metadata->sizeBytes;
        $instance->durationSeconds = $metadata->durationSeconds;
        $instance->keep = $metadata->keep;
        $instance->status = $metadata->status->value;
        $instance->failureReason = $metadata->failureReason;
        $instance->dumpBytes = $metadata->dumpBytes;
        $instance->sha256 = $metadata->sha256;
        $instance->verifiedAt = $metadata->verifiedAt;
        $instance->verifyOutcome = $metadata->verifyOutcome?->value;
        $instance->restoredAt = $metadata->restoredAt;
        $instance->restoreDurationSeconds = $metadata->restoreDurationSeconds;
        $instance->shippedAt = $metadata->shippedAt;
        $instance->shipOutcome = $metadata->shipOutcome?->value;
        $instance->shipError = $metadata->shipError;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    public static function fromRow(array $row): static
    {
        $connections = [];
        foreach ((array)($row[self::connections] ?? []) as $connectionRow) {
            if (is_array($connectionRow)) {
                $connections[] = BackupConnectionMeta::fromArray($connectionRow);
            }
        }

        $instance = new static();
        $instance->id = (string)$row[self::id];
        $instance->createdAt = (string)$row[self::createdAt];
        $instance->env = (string)$row[self::env];
        $instance->scope = self::stringOrNull($row[self::scope] ?? null);
        $instance->connections = $connections;
        $instance->sizeBytes = (int)($row[self::sizeBytes] ?? 0);
        $instance->durationSeconds = (int)($row[self::durationSeconds] ?? 0);
        $instance->keep = (bool)($row[self::keep] ?? false);
        $instance->status = (string)$row[self::status];
        $instance->failureReason = isset($row[self::failureReason]) ? (string)$row[self::failureReason] : null;
        $instance->dumpBytes = (int)($row[self::dumpBytes] ?? 0);
        $instance->sha256 = isset($row[self::sha256]) ? (string)$row[self::sha256] : null;
        $instance->verifiedAt = isset($row[self::verifiedAt]) ? (string)$row[self::verifiedAt] : null;
        $instance->verifyOutcome = isset($row[self::verifyOutcome]) ? (string)$row[self::verifyOutcome] : null;
        $instance->restoredAt = isset($row[self::restoredAt]) ? (string)$row[self::restoredAt] : null;
        $instance->restoreDurationSeconds = (int)($row[self::restoreDurationSeconds] ?? 0);
        $instance->shippedAt = isset($row[self::shippedAt]) ? (string)$row[self::shippedAt] : null;
        $instance->shipOutcome = isset($row[self::shipOutcome]) ? (string)$row[self::shipOutcome] : null;
        $instance->shipError = isset($row[self::shipError]) ? (string)$row[self::shipError] : null;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * Without it a synced update is silently dropped, and every worker but the writer keeps
     * the row it first received - a pinned backup would stay unpinned everywhere else.
     *
     * @param array<string, mixed> $diff Changed fields and values from another worker
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::createdAt, $diff)) {
            $this->createdAt = (string)$diff[self::createdAt];
        }
        if (array_key_exists(self::env, $diff)) {
            $this->env = (string)$diff[self::env];
        }
        if (array_key_exists(self::scope, $diff)) {
            $this->scope = (string)$diff[self::scope];
        }
        if (array_key_exists(self::connections, $diff)) {
            $connections = [];
            foreach ((array)$diff[self::connections] as $connectionRow) {
                if (is_array($connectionRow)) {
                    $connections[] = BackupConnectionMeta::fromArray($connectionRow);
                }
            }
            $this->connections = $connections;
        }
        if (array_key_exists(self::sizeBytes, $diff)) {
            $this->sizeBytes = (int)$diff[self::sizeBytes];
        }
        if (array_key_exists(self::durationSeconds, $diff)) {
            $this->durationSeconds = (int)$diff[self::durationSeconds];
        }
        if (array_key_exists(self::keep, $diff)) {
            $this->keep = (bool)$diff[self::keep];
        }
        if (array_key_exists(self::status, $diff)) {
            $this->status = (string)$diff[self::status];
        }
        if (array_key_exists(self::failureReason, $diff)) {
            $this->failureReason = $diff[self::failureReason] === null ? null : (string)$diff[self::failureReason];
        }
        if (array_key_exists(self::dumpBytes, $diff)) {
            $this->dumpBytes = (int)$diff[self::dumpBytes];
        }
        if (array_key_exists(self::sha256, $diff)) {
            $this->sha256 = $diff[self::sha256] === null ? null : (string)$diff[self::sha256];
        }
        if (array_key_exists(self::verifiedAt, $diff)) {
            $this->verifiedAt = $diff[self::verifiedAt] === null ? null : (string)$diff[self::verifiedAt];
        }
        if (array_key_exists(self::verifyOutcome, $diff)) {
            $this->verifyOutcome = $diff[self::verifyOutcome] === null ? null : (string)$diff[self::verifyOutcome];
        }
        if (array_key_exists(self::restoredAt, $diff)) {
            $this->restoredAt = $diff[self::restoredAt] === null ? null : (string)$diff[self::restoredAt];
        }
        if (array_key_exists(self::restoreDurationSeconds, $diff)) {
            $this->restoreDurationSeconds = (int)$diff[self::restoreDurationSeconds];
        }
        if (array_key_exists(self::shippedAt, $diff)) {
            $this->shippedAt = $diff[self::shippedAt] === null ? null : (string)$diff[self::shippedAt];
        }
        if (array_key_exists(self::shipOutcome, $diff)) {
            $this->shipOutcome = $diff[self::shipOutcome] === null ? null : (string)$diff[self::shipOutcome];
        }
        if (array_key_exists(self::shipError, $diff)) {
            $this->shipError = $diff[self::shipError] === null ? null : (string)$diff[self::shipError];
        }
    }

    /**
     * @return string Runtime collection key for backup history rows
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, the backup id
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::createdAt => $this->createdAt,
            self::env => $this->env,
            self::scope => $this->scope,
            self::connections => array_map(
                static fn(BackupConnectionMeta $connection): array => $connection->toArray(),
                $this->connections,
            ),
            self::sizeBytes => $this->sizeBytes,
            self::durationSeconds => $this->durationSeconds,
            self::keep => $this->keep,
            self::status => $this->status,
            self::failureReason => $this->failureReason,
            self::dumpBytes => $this->dumpBytes,
            self::sha256 => $this->sha256,
            self::verifiedAt => $this->verifiedAt,
            self::verifyOutcome => $this->verifyOutcome,
            self::restoredAt => $this->restoredAt,
            self::restoreDurationSeconds => $this->restoreDurationSeconds,
            self::shippedAt => $this->shippedAt,
            self::shipOutcome => $this->shipOutcome,
            self::shipError => $this->shipError,
        ];
    }

    /**
     * @param mixed $value Raw row value
     * @return ?string String value, or null
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}

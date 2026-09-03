<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Core\Exception\InvalidFormatException;
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
    public const string shipEncryption = 'shipEncryption';

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
     * Fingerprint of the recipient set the last copy was encrypted to; null means it left in the
     * clear. The shipping planner compares it with the fingerprint configured now, which is what
     * makes turning a key on, off, or over re-send the store without a migration command of its
     * own.
     */
    public ?string $shipEncryption = null;

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
        $instance->shipEncryption = $metadata->shipEncryption;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static History row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the index is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = self::requireString($row, self::id);
        $instance->createdAt = self::requireString($row, self::createdAt);
        $instance->env = self::requireString($row, self::env);
        $instance->scope = self::optionalString($row, self::scope);
        $instance->connections = self::readConnections($row);
        $instance->sizeBytes = self::requireInt($row, self::sizeBytes);
        $instance->durationSeconds = self::requireInt($row, self::durationSeconds);
        $instance->keep = self::requireBool($row, self::keep);
        $instance->status = self::requireString($row, self::status);
        $instance->failureReason = self::optionalString($row, self::failureReason);
        $instance->dumpBytes = self::requireInt($row, self::dumpBytes);
        $instance->sha256 = self::optionalString($row, self::sha256);
        $instance->verifiedAt = self::optionalString($row, self::verifiedAt);
        $instance->verifyOutcome = self::optionalString($row, self::verifyOutcome);
        $instance->restoredAt = self::optionalString($row, self::restoredAt);
        $instance->restoreDurationSeconds = self::requireInt($row, self::restoreDurationSeconds);
        $instance->shippedAt = self::optionalString($row, self::shippedAt);
        $instance->shipOutcome = self::optionalString($row, self::shipOutcome);
        $instance->shipError = self::optionalString($row, self::shipError);
        $instance->shipEncryption = self::optionalString($row, self::shipEncryption);
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
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->createdAt = self::patchString($diff, self::createdAt, $this->createdAt);
        $this->env = self::patchString($diff, self::env, $this->env);
        $this->scope = self::patchOptionalString($diff, self::scope, $this->scope);
        if (array_key_exists(self::connections, $diff)) {
            $this->connections = self::readConnections($diff);
        }
        $this->sizeBytes = self::patchInt($diff, self::sizeBytes, $this->sizeBytes);
        $this->durationSeconds = self::patchInt($diff, self::durationSeconds, $this->durationSeconds);
        $this->keep = self::patchBool($diff, self::keep, $this->keep);
        $this->status = self::patchString($diff, self::status, $this->status);
        $this->failureReason = self::patchOptionalString($diff, self::failureReason, $this->failureReason);
        $this->dumpBytes = self::patchInt($diff, self::dumpBytes, $this->dumpBytes);
        $this->sha256 = self::patchOptionalString($diff, self::sha256, $this->sha256);
        $this->verifiedAt = self::patchOptionalString($diff, self::verifiedAt, $this->verifiedAt);
        $this->verifyOutcome = self::patchOptionalString($diff, self::verifyOutcome, $this->verifyOutcome);
        $this->restoredAt = self::patchOptionalString($diff, self::restoredAt, $this->restoredAt);
        $this->restoreDurationSeconds = self::patchInt($diff, self::restoreDurationSeconds, $this->restoreDurationSeconds);
        $this->shippedAt = self::patchOptionalString($diff, self::shippedAt, $this->shippedAt);
        $this->shipOutcome = self::patchOptionalString($diff, self::shipOutcome, $this->shipOutcome);
        $this->shipError = self::patchOptionalString($diff, self::shipError, $this->shipError);
        $this->shipEncryption = self::patchOptionalString($diff, self::shipEncryption, $this->shipEncryption);
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
            self::shipEncryption => $this->shipEncryption,
        ];
    }

    /**
     * Reads the connection list, the one field of this row that is a list of objects.
     *
     * The base readers stop at scalars and lists of strings, so the shape gets a reader of its
     * own rather than a cast: a row whose connections are not a list of arrays is a row this
     * state cannot describe, and the refusal is the same one every other field raises.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @return list<BackupConnectionMeta> Connections captured in the backup
     * @throws InvalidFormatException When the key is absent, holds a non-array, or holds a non-array element
     */
    private static function readConnections(array $source): array
    {
        $connections = [];
        foreach (self::requireArray($source, self::connections) as $connectionRow) {
            if (!is_array($connectionRow)) {
                throw new InvalidFormatException(
                    'Runtime row carries a non-array connection under key ' . self::connections,
                );
            }
            $connections[] = BackupConnectionMeta::fromArray($connectionRow);
        }

        return $connections;
    }
}

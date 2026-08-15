<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\BackupMetadataIncompleteException;
use Hilos\BaseDTO;
use Hilos\Runtime\RtSyncApplicator;

/**
 * BackupMetadata - the JSON sidecar written next to a backup archive.
 *
 * The sidecar is the source of truth for the backup index (files=truth, RT=index):
 * the read path scans sidecars and rebuilds the runtime history from them, never
 * from the archives themselves. The create path (HIL-270) writes it.
 */
final class BackupMetadata extends BaseDTO
{
    public const string id = 'id';
    public const string createdAt = 'createdAt';
    public const string env = 'env';
    public const string scope = 'scope';
    public const string connections = 'connections';
    public const string sizeBytes = 'sizeBytes';
    public const string durationSeconds = 'durationSeconds';
    public const string keep = 'keep';
    public const string status = 'status';
    public const string warnings = 'warnings';
    public const string failureReason = 'failureReason';
    public const string dumpBytes = 'dumpBytes';
    public const string sha256 = 'sha256';
    public const string verifiedAt = 'verifiedAt';
    public const string verifyOutcome = 'verifyOutcome';
    public const string restoredAt = 'restoredAt';
    public const string restoreDurationSeconds = 'restoreDurationSeconds';

    /**
     * @param string $id Backup id (also the archive/sidecar base name)
     * @param string $createdAt ISO-8601 creation timestamp
     * @param string $env Application environment the backup was taken in
     * @param BackupScope $scope What the backup captures
     * @param list<BackupConnectionMeta> $connections Connections captured
     * @param int $sizeBytes Archive size in bytes
     * @param int $durationSeconds Wall-clock capture duration in seconds
     * @param bool $keep Retention pin: true excludes the backup from rotation
     * @param BackupStatus $status Terminal outcome
     * @param list<string> $warnings Non-fatal notes about the run (e.g. schema-seed found no reference tables)
     * @param ?string $failureReason Why the run failed (error records only); null for success and legacy sidecars
     * @param int $dumpBytes Uncompressed dump volume in bytes (the true peak the space guard sizes runs
     *     from); 0 means "no data" for error records and legacy sidecars written before the field existed
     * @param ?string $sha256 Lowercase hex digest of the archive; null on error records and on sidecars
     *     written before the digest existed - "nothing to check", not "corrupt"
     * @param ?string $verifiedAt ISO-8601 instant of the last verification; null means never verified
     * @param ?BackupVerifyOutcome $verifyOutcome Outcome of that verification; only ok/mismatch are ever
     *     stored, and an unknown stored value reads back as null
     * @param ?string $restoredAt ISO-8601 instant this archive was last restored from; null means never
     * @param int $restoreDurationSeconds How long that restore took; 0 means "no data", the same way
     *     dumpBytes does - the archive was never restored, or it was before the field existed
     */
    public function __construct(
        public readonly string $id,
        public readonly string $createdAt,
        public readonly string $env,
        public readonly BackupScope $scope,
        public readonly array $connections,
        public readonly int $sizeBytes,
        public readonly int $durationSeconds,
        public readonly bool $keep,
        public readonly BackupStatus $status,
        public readonly array $warnings = [],
        public readonly ?string $failureReason = null,
        public readonly int $dumpBytes = 0,
        public readonly ?string $sha256 = null,
        public readonly ?string $verifiedAt = null,
        public readonly ?BackupVerifyOutcome $verifyOutcome = null,
        public readonly ?string $restoredAt = null,
        public readonly int $restoreDurationSeconds = 0,
    ) {
    }

    /**
     * Returns a copy carrying a different retention pin.
     *
     * @param bool $keep Desired retention pin
     * @return self Copy with the pin applied
     */
    public function withKeep(bool $keep): self
    {
        return new self(
            $this->id,
            $this->createdAt,
            $this->env,
            $this->scope,
            $this->connections,
            $this->sizeBytes,
            $this->durationSeconds,
            $keep,
            $this->status,
            $this->warnings,
            $this->failureReason,
            $this->dumpBytes,
            $this->sha256,
            $this->verifiedAt,
            $this->verifyOutcome,
            $this->restoredAt,
            $this->restoreDurationSeconds,
        );
    }

    /**
     * Returns a copy carrying the result of a verification run.
     *
     * @param ?string $verifiedAt ISO-8601 instant the archive was verified
     * @param ?BackupVerifyOutcome $verifyOutcome What that verification concluded
     * @return self Copy with the verification recorded
     */
    public function withVerification(?string $verifiedAt, ?BackupVerifyOutcome $verifyOutcome): self
    {
        return new self(
            $this->id,
            $this->createdAt,
            $this->env,
            $this->scope,
            $this->connections,
            $this->sizeBytes,
            $this->durationSeconds,
            $this->keep,
            $this->status,
            $this->warnings,
            $this->failureReason,
            $this->dumpBytes,
            $this->sha256,
            $verifiedAt,
            $verifyOutcome,
            $this->restoredAt,
            $this->restoreDurationSeconds,
        );
    }

    /**
     * Returns a copy carrying the result of a restore run made from this archive.
     *
     * Written after the fact, the same way {@see self::withVerification()} is: the sidecar is the
     * only history a restore has, and the estimate of the next one is read back out of it.
     *
     * @param string $restoredAt ISO-8601 instant the restore finished
     * @param int $durationSeconds How long that restore took, in seconds
     * @return self Copy with the restore recorded
     */
    public function withRestore(string $restoredAt, int $durationSeconds): self
    {
        return new self(
            $this->id,
            $this->createdAt,
            $this->env,
            $this->scope,
            $this->connections,
            $this->sizeBytes,
            $this->durationSeconds,
            $this->keep,
            $this->status,
            $this->warnings,
            $this->failureReason,
            $this->dumpBytes,
            $this->sha256,
            $this->verifiedAt,
            $this->verifyOutcome,
            $restoredAt,
            $durationSeconds,
        );
    }

    /**
     * @param array<string, mixed> $data Sidecar payload
     * @return static Restored backup metadata (unknown scope/status fall back to full/success)
     * @throws BackupMetadataIncompleteException When the sidecar carries no id, creation
     *                                          timestamp, or environment
     */
    public static function fromArray(array $data): static
    {
        $connections = [];
        foreach ((array)($data[self::connections] ?? []) as $connectionRow) {
            if (is_array($connectionRow)) {
                $connections[] = BackupConnectionMeta::fromArray($connectionRow);
            }
        }

        $warnings = [];
        foreach ((array)($data[self::warnings] ?? []) as $warning) {
            $warnings[] = (string)$warning;
        }

        return new self(
            self::identityField($data, self::id),
            self::identityField($data, self::createdAt),
            self::identityField($data, self::env),
            // external-boundary: the sidecar is read from disk and an unnamed scope collapses into the default
            BackupScope::fromString((string)($data[self::scope] ?? '')) ?? BackupScope::FULL,
            $connections,
            // external-boundary: the sidecar is read from disk and may come from an older version
            (int)($data[self::sizeBytes] ?? 0),
            // external-boundary: the sidecar is read from disk and may come from an older version
            (int)($data[self::durationSeconds] ?? 0),
            (bool)($data[self::keep] ?? false),
            // external-boundary: the sidecar is read from disk and an unnamed status collapses into the default
            BackupStatus::fromString((string)($data[self::status] ?? '')) ?? BackupStatus::SUCCESS,
            $warnings,
            isset($data[self::failureReason]) ? (string)$data[self::failureReason] : null,
            // external-boundary: the sidecar is read from disk and may come from an older version
            (int)($data[self::dumpBytes] ?? 0),
            isset($data[self::sha256]) ? (string)$data[self::sha256] : null,
            isset($data[self::verifiedAt]) ? (string)$data[self::verifiedAt] : null,
            BackupVerifyOutcome::fromString(
                isset($data[self::verifyOutcome]) ? (string)$data[self::verifyOutcome] : null,
            ),
            isset($data[self::restoredAt]) ? (string)$data[self::restoredAt] : null,
            // external-boundary: the sidecar is read from disk and may come from an older version
            (int)($data[self::restoreDurationSeconds] ?? 0),
        );
    }

    /**
     * Reads a sidecar field the backup is addressed by, refusing a record that carries none.
     *
     * Unlike scope or status, these fields have no meaningful default: an empty one would travel
     * on as an empty runtime row id (which {@see RtSyncApplicator} drops, so the
     * row never appears), as a "no time" marker retention reads as unprunable, and as a hole in
     * the archive base name, making prune and delete miss the file they were aimed at.
     *
     * @param array<string, mixed> $data Sidecar payload
     * @param string $key Field name
     * @return string Non-empty field value
     * @throws BackupMetadataIncompleteException When the field is absent or empty
     */
    private static function identityField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_scalar($value) || (string)$value === '') {
            throw new BackupMetadataIncompleteException("Backup sidecar carries no '{$key}'");
        }

        return (string)$value;
    }

    /**
     * @return array<string, mixed> Sidecar payload
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::createdAt => $this->createdAt,
            self::env => $this->env,
            self::scope => $this->scope->value,
            self::connections => array_map(
                static fn(BackupConnectionMeta $connection): array => $connection->toArray(),
                $this->connections,
            ),
            self::sizeBytes => $this->sizeBytes,
            self::durationSeconds => $this->durationSeconds,
            self::keep => $this->keep,
            self::status => $this->status->value,
            self::warnings => $this->warnings,
            // Always written, including null: an error sidecar keys off it, and a null on a
            // success record makes the field's absence explicit rather than ambiguous.
            self::failureReason => $this->failureReason,
            self::dumpBytes => $this->dumpBytes,
            // Written even when null, for the same reason failureReason is: a reader tells
            // "this backup carries no digest" from "this sidecar predates the field".
            self::sha256 => $this->sha256,
            self::verifiedAt => $this->verifiedAt,
            self::verifyOutcome => $this->verifyOutcome?->value,
            self::restoredAt => $this->restoredAt,
            self::restoreDurationSeconds => $this->restoreDurationSeconds,
        ];
    }
}

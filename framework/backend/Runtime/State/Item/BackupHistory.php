<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupMetadata;

/**
 * BackupHistory - one runtime index row for a stored backup.
 *
 * Framework-owned runtime state: the project registers the backing
 * {@see \Hilos\Runtime\State\Collection\BackupHistories} collection under
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

    /** Backup id (also the archive/sidecar base name). */
    private(set) string $id = '';

    /** ISO-8601 creation timestamp. */
    public string $createdAt = '';

    /** Application environment the backup was taken in. */
    public string $env = '';

    /** Scope value ({@see \Hilos\Backup\BackupScope}). */
    public string $scope = '';

    /** @var list<BackupConnectionMeta> Connections captured in the backup. */
    public array $connections = [];

    /** Archive size in bytes. */
    public int $sizeBytes = 0;

    /** Wall-clock capture duration in seconds. */
    public int $durationSeconds = 0;

    /** Retention pin: true excludes the backup from rotation. */
    public bool $keep = false;

    /** Status value ({@see \Hilos\Backup\BackupStatus}). */
    public string $status = '';

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
        $instance->id = (string)($row[self::id] ?? '');
        $instance->createdAt = (string)($row[self::createdAt] ?? '');
        $instance->env = (string)($row[self::env] ?? '');
        $instance->scope = (string)($row[self::scope] ?? '');
        $instance->connections = $connections;
        $instance->sizeBytes = (int)($row[self::sizeBytes] ?? 0);
        $instance->durationSeconds = (int)($row[self::durationSeconds] ?? 0);
        $instance->keep = (bool)($row[self::keep] ?? false);
        $instance->status = (string)($row[self::status] ?? '');
        $instance->markRtSyncBaseline();

        return $instance;
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
        ];
    }
}

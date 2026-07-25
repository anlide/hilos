<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

/**
 * BackupRuntime - the singleton runtime state of the backup subsystem.
 *
 * Framework-owned runtime state registered by the project as a single item.
 * Tracks whether a backup is in progress so the create path (HIL-270) can
 * enforce single-flight; in this foundation it is created idle. The monopoly
 * backup agent is its single writer.
 */
final class BackupRuntime extends RtState
{
    /** Runtime item alias registered by the project and used for RT sync. */
    public const string RT_ITEM = 'hilosBackupRuntime';

    /** Stable singleton row id. */
    public const string ID = 'runtime';

    public const string running = 'running';
    public const string currentBackupId = 'currentBackupId';
    public const string scope = 'scope';
    public const string startedAt = 'startedAt';

    /** Whether a backup is currently running. */
    public bool $running = false;

    /** Id of the backup in progress, or null when idle. */
    public ?string $currentBackupId = null;

    /** Scope value of the backup in progress, or null when idle. */
    public ?string $scope = null;

    /** ISO-8601 start time of the backup in progress, or null when idle. */
    public ?string $startedAt = null;

    /**
     * Creates the idle singleton runtime row.
     *
     * @return static Idle backup runtime state
     */
    public static function create(): static
    {
        $instance = new static();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    public static function fromRow(array $row): static
    {
        $currentBackupId = $row[self::currentBackupId] ?? null;
        $scope = $row[self::scope] ?? null;
        $startedAt = $row[self::startedAt] ?? null;

        $instance = new static();
        $instance->running = (bool)($row[self::running] ?? false);
        $instance->currentBackupId = $currentBackupId === null ? null : (string)$currentBackupId;
        $instance->scope = $scope === null ? null : (string)$scope;
        $instance->startedAt = $startedAt === null ? null : (string)$startedAt;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @return string Runtime collection key for the backup runtime singleton
     */
    /**
     * Applies an inbound RT sync diff to this singleton.
     *
     * The in-progress backup row is delivered as diffs of this item, so without it every
     * worker but the agent's would show no running backup at all.
     *
     * @param array<string, mixed> $diff Changed fields and values from another worker
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::running, $diff)) {
            $this->running = (bool)$diff[self::running];
        }
        if (array_key_exists(self::currentBackupId, $diff)) {
            $value = $diff[self::currentBackupId];
            $this->currentBackupId = $value === null ? null : (string)$value;
        }
        if (array_key_exists(self::scope, $diff)) {
            $value = $diff[self::scope];
            $this->scope = $value === null ? null : (string)$value;
        }
        if (array_key_exists(self::startedAt, $diff)) {
            $value = $diff[self::startedAt];
            $this->startedAt = $value === null ? null : (string)$value;
        }
    }

    /**
     * @return string Runtime collection key for the backup runtime singleton
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_ITEM;
    }

    /**
     * @return string Stable singleton row id
     */
    public function getId(): string
    {
        return self::ID;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::running => $this->running,
            self::currentBackupId => $this->currentBackupId,
            self::scope => $this->scope,
            self::startedAt => $this->startedAt,
        ];
    }
}

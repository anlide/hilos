<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Backup\BackupPhase;
use Hilos\Core\Exception\InvalidFormatException;

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
    public const string phase = 'phase';
    public const string phaseStartedAt = 'phaseStartedAt';
    public const string estimatedSeconds = 'estimatedSeconds';

    /** Whether a backup is currently running. */
    public bool $running = false;

    /** Id of the backup in progress, or null when idle. */
    public ?string $currentBackupId = null;

    /** Scope value of the backup in progress, or null when idle. */
    public ?string $scope = null;

    /** ISO-8601 start time of the backup in progress, or null when idle. */
    public ?string $startedAt = null;

    /** Phase value ({@see BackupPhase}) the run is in, or null when idle or not yet reported. */
    public ?string $phase = null;

    /** ISO-8601 instant the current phase began, or null when there is no phase. */
    public ?string $phaseStartedAt = null;

    /**
     * How long the run is expected to take in seconds, or null when there is no history to
     * estimate from. The three anchors are what a progress bar is drawn from: the percentage and
     * the time left are computed by whoever shows them, so this row is written only when a phase
     * changes rather than on a timer.
     */
    public ?int $estimatedSeconds = null;

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
     * @return static Runtime singleton restored from a sync row
     * @throws InvalidFormatException When the row lost the running flag or carries a field as another type
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->running = self::requireBool($row, self::running);
        $instance->currentBackupId = self::optionalString($row, self::currentBackupId);
        $instance->scope = self::optionalString($row, self::scope);
        $instance->startedAt = self::optionalString($row, self::startedAt);
        $instance->phase = self::optionalString($row, self::phase);
        $instance->phaseStartedAt = self::optionalString($row, self::phaseStartedAt);
        $instance->estimatedSeconds = self::optionalInt($row, self::estimatedSeconds);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this singleton.
     *
     * The in-progress backup row is delivered as diffs of this item, so without it every
     * worker but the agent's would show no running backup at all.
     *
     * @param array<string, mixed> $diff Changed fields and values from another worker
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->running = self::patchBool($diff, self::running, $this->running);
        $this->currentBackupId = self::patchOptionalString($diff, self::currentBackupId, $this->currentBackupId);
        $this->scope = self::patchOptionalString($diff, self::scope, $this->scope);
        $this->startedAt = self::patchOptionalString($diff, self::startedAt, $this->startedAt);
        $this->phase = self::patchOptionalString($diff, self::phase, $this->phase);
        $this->phaseStartedAt = self::patchOptionalString($diff, self::phaseStartedAt, $this->phaseStartedAt);
        $this->estimatedSeconds = self::patchOptionalInt($diff, self::estimatedSeconds, $this->estimatedSeconds);
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
            self::phase => $this->phase,
            self::phaseStartedAt => $this->phaseStartedAt,
            self::estimatedSeconds => $this->estimatedSeconds,
        ];
    }
}

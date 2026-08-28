<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Backup\BackupStatus;
use Hilos\Backup\RestorePhase;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * RestoreRuntime - the singleton runtime state of a restore run.
 *
 * Framework-owned mirror of {@see BackupRuntime} for the restore path (HIL-274): one
 * row saying whether a restore is running, which backup it replays and how far it got.
 * The monopoly backup agent is its single writer; the CLI monitor reads it through the
 * agent's status reply. Unlike the create row it also keeps its terminal outcome after
 * the run, so a monitor that polls just after the finish still learns how it ended.
 */
final class RestoreRuntime extends RtState
{
    /** Runtime item alias registered by the framework mount and used for RT sync. */
    public const string RT_ITEM = 'hilosRestoreRuntime';

    /** Stable singleton row id. */
    public const string ID = 'runtime';

    public const string running = 'running';
    public const string backupId = 'backupId';
    public const string scope = 'scope';
    public const string phase = 'phase';
    public const string phaseStartedAt = 'phaseStartedAt';
    public const string startedAt = 'startedAt';
    public const string estimatedSeconds = 'estimatedSeconds';
    public const string finishedAt = 'finishedAt';
    public const string outcome = 'outcome';
    public const string failureReason = 'failureReason';
    public const string rehydrateComplete = 'rehydrateComplete';
    public const string rehydrateProblems = 'rehydrateProblems';
    public const string databaseTouched = 'databaseTouched';

    /** Whether a restore is currently running. */
    public bool $running = false;

    /** Id of the backup being restored, or null when idle. */
    public ?string $backupId = null;

    /** Scope value of the backup being restored, or null when idle. */
    public ?string $scope = null;

    /** Current {@see RestorePhase} value, or null when idle. */
    public ?string $phase = null;

    /** ISO-8601 instant the current phase began, or null when there is no phase. */
    public ?string $phaseStartedAt = null;

    /** ISO-8601 start time of the restore in progress, or null when idle. */
    public ?string $startedAt = null;

    /** ISO-8601 finish time of the last restore, or null while running or idle. */
    public ?string $finishedAt = null;

    /** Terminal {@see BackupStatus} value of the last restore, or null. */
    public ?string $outcome = null;

    /** Why the last restore failed, or null when it succeeded or never ran. */
    public ?string $failureReason = null;

    /**
     * How long the restore is expected to take in seconds, or null when no restore of this scope
     * has been recorded yet. It sits beside the phase and the instant it began because those three
     * are what a progress bar is drawn from - the percentage and the time left are computed by
     * whoever shows them, which is why this row is written on a phase change and never on a timer.
     */
    public ?int $estimatedSeconds = null;

    /** Whether every process confirmed re-reading the replaced database (HIL-436). */
    public bool $rehydrateComplete = false;

    /**
     * @var list<string> Processes that failed to re-read or never answered, one line each
     *
     * Empty is not the same as good: it is also what an idle row says. The barrier's verdict is
     * {@see self::$rehydrateComplete}; this names who is behind a negative one, so the operator
     * knows which process to look at instead of being told only that the node stayed closed.
     */
    public array $rehydrateProblems = [];

    /**
     * @var bool Whether the run reached its first destructive step
     *
     * The difference between "the database is untouched, nothing was lost" and "the database may be
     * half-overwritten", which is the first thing an operator needs to know from a failed restore.
     */
    public bool $databaseTouched = false;

    /**
     * Creates the idle singleton runtime row.
     *
     * @return static Idle restore runtime state
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
     * @throws InvalidFormatException When the row lost a flag or list, or carries a field as another type
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->running = self::requireBool($row, self::running);
        $instance->backupId = self::optionalString($row, self::backupId);
        $instance->scope = self::optionalString($row, self::scope);
        $instance->phase = self::optionalString($row, self::phase);
        $instance->phaseStartedAt = self::optionalString($row, self::phaseStartedAt);
        $instance->startedAt = self::optionalString($row, self::startedAt);
        $instance->finishedAt = self::optionalString($row, self::finishedAt);
        $instance->outcome = self::optionalString($row, self::outcome);
        $instance->failureReason = self::optionalString($row, self::failureReason);
        $instance->estimatedSeconds = self::optionalInt($row, self::estimatedSeconds);
        $instance->rehydrateComplete = self::requireBool($row, self::rehydrateComplete);
        $instance->rehydrateProblems = self::requireStringList($row, self::rehydrateProblems);
        $instance->databaseTouched = self::requireBool($row, self::databaseTouched);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this singleton.
     *
     * The restore progress is delivered as diffs of this item, so without it every
     * worker but the agent's would keep showing an idle row.
     *
     * @param array<string, mixed> $diff Changed fields and values from another worker
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->running = self::patchBool($diff, self::running, $this->running);
        $this->backupId = self::patchOptionalString($diff, self::backupId, $this->backupId);
        $this->scope = self::patchOptionalString($diff, self::scope, $this->scope);
        $this->phase = self::patchOptionalString($diff, self::phase, $this->phase);
        $this->phaseStartedAt = self::patchOptionalString($diff, self::phaseStartedAt, $this->phaseStartedAt);
        $this->startedAt = self::patchOptionalString($diff, self::startedAt, $this->startedAt);
        $this->finishedAt = self::patchOptionalString($diff, self::finishedAt, $this->finishedAt);
        $this->outcome = self::patchOptionalString($diff, self::outcome, $this->outcome);
        $this->failureReason = self::patchOptionalString($diff, self::failureReason, $this->failureReason);
        $this->estimatedSeconds = self::patchOptionalInt($diff, self::estimatedSeconds, $this->estimatedSeconds);
        $this->rehydrateComplete = self::patchBool($diff, self::rehydrateComplete, $this->rehydrateComplete);
        $this->rehydrateProblems = self::patchStringList($diff, self::rehydrateProblems, $this->rehydrateProblems);
        $this->databaseTouched = self::patchBool($diff, self::databaseTouched, $this->databaseTouched);
    }

    /**
     * @return string Runtime collection key for the restore runtime singleton
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
            self::backupId => $this->backupId,
            self::scope => $this->scope,
            self::phase => $this->phase,
            self::phaseStartedAt => $this->phaseStartedAt,
            self::startedAt => $this->startedAt,
            self::finishedAt => $this->finishedAt,
            self::outcome => $this->outcome,
            self::failureReason => $this->failureReason,
            self::estimatedSeconds => $this->estimatedSeconds,
            self::rehydrateComplete => $this->rehydrateComplete,
            self::rehydrateProblems => $this->rehydrateProblems,
            self::databaseTouched => $this->databaseTouched,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Backup\BackupStatus;
use Hilos\Backup\RestorePhase;

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
    public const string startedAt = 'startedAt';
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

    /** ISO-8601 start time of the restore in progress, or null when idle. */
    public ?string $startedAt = null;

    /** ISO-8601 finish time of the last restore, or null while running or idle. */
    public ?string $finishedAt = null;

    /** Terminal {@see BackupStatus} value of the last restore, or null. */
    public ?string $outcome = null;

    /** Why the last restore failed, or null when it succeeded or never ran. */
    public ?string $failureReason = null;

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
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->running = (bool)($row[self::running] ?? false);
        $instance->backupId = self::nullableString($row[self::backupId] ?? null);
        $instance->scope = self::nullableString($row[self::scope] ?? null);
        $instance->phase = self::nullableString($row[self::phase] ?? null);
        $instance->startedAt = self::nullableString($row[self::startedAt] ?? null);
        $instance->finishedAt = self::nullableString($row[self::finishedAt] ?? null);
        $instance->outcome = self::nullableString($row[self::outcome] ?? null);
        $instance->failureReason = self::nullableString($row[self::failureReason] ?? null);
        $instance->rehydrateComplete = (bool)($row[self::rehydrateComplete] ?? false);
        $instance->rehydrateProblems = self::stringList($row[self::rehydrateProblems] ?? []);
        $instance->databaseTouched = (bool)($row[self::databaseTouched] ?? false);
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
     */
    public function applyDiff(array $diff): void
    {
        foreach ([self::running, self::rehydrateComplete, self::databaseTouched] as $flag) {
            if (array_key_exists($flag, $diff)) {
                $this->{$flag} = (bool)$diff[$flag];
            }
        }
        if (array_key_exists(self::rehydrateProblems, $diff)) {
            $this->rehydrateProblems = self::stringList($diff[self::rehydrateProblems]);
        }
        foreach (
            [
                self::backupId,
                self::scope,
                self::phase,
                self::startedAt,
                self::finishedAt,
                self::outcome,
                self::failureReason,
            ] as $field
        ) {
            if (array_key_exists($field, $diff)) {
                $this->{$field} = self::nullableString($diff[$field]);
            }
        }
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
            self::startedAt => $this->startedAt,
            self::finishedAt => $this->finishedAt,
            self::outcome => $this->outcome,
            self::failureReason => $this->failureReason,
            self::rehydrateComplete => $this->rehydrateComplete,
            self::rehydrateProblems => $this->rehydrateProblems,
            self::databaseTouched => $this->databaseTouched,
        ];
    }

    /**
     * @param mixed $value Raw row value
     * @return list<string> Value as a list of strings, empty when the row carried no list
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(strval(...), $value));
    }

    /**
     * @param mixed $value Raw row value
     * @return ?string Value as string, null preserved
     */
    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}

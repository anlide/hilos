<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent\DTO;

use Hilos\Backup\BackupStatus;
use Hilos\Backup\RestorePhase;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\View\Item\RestoreRuntime;

/**
 * BackupRestoreProgressSignalData - BackupAgent → restore initiator progress frame.
 *
 * One snapshot of the restore runtime row, addressed to the connection that asked for the
 * run. It exists because the freeze stops everything else that could report: the page's own
 * agent is down for the length of the operation, so the list table produces no deltas and
 * the only live channel left is the one protected mode holds open for the initiator.
 *
 * Its keys are the runtime row's keys, one for one, and {@see fromRuntime()} is the only
 * way this class is built on the sending side - the CLI monitor is answered with the very
 * same snapshot ({@see RestoreRuntime::toArray()}), and two representations of one run that
 * are assembled apart drift apart.
 */
final class BackupRestoreProgressSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: whether a restore is running right now. */
    public const string running = 'running';

    /** Payload key: the archive being replayed. */
    public const string backupId = 'backupId';

    /** Payload key: the scope value the archive was captured under. */
    public const string scope = 'scope';

    /** Payload key: the current {@see RestorePhase} value. */
    public const string phase = 'phase';

    /** Payload key: ISO-8601 instant the current phase began. */
    public const string phaseStartedAt = 'phaseStartedAt';

    /** Payload key: ISO-8601 start time of the run. */
    public const string startedAt = 'startedAt';

    /** Payload key: ISO-8601 finish time of the run. */
    public const string finishedAt = 'finishedAt';

    /** Payload key: the terminal {@see BackupStatus} value. */
    public const string outcome = 'outcome';

    /** Payload key: why the run failed. */
    public const string failureReason = 'failureReason';

    /** Payload key: how long the run is expected to take, in seconds. */
    public const string estimatedSeconds = 'estimatedSeconds';

    /** Payload key: whether every process confirmed re-reading the replaced database. */
    public const string rehydrateComplete = 'rehydrateComplete';

    /** Payload key: processes that failed to re-read or never answered. */
    public const string rehydrateProblems = 'rehydrateProblems';

    /** Payload key: whether the run reached its first destructive step. */
    public const string databaseTouched = 'databaseTouched';

    /**
     * @param bool $running Whether a restore is running right now
     * @param ?string $backupId Id of the archive being replayed; null when idle
     * @param ?string $scope Scope value of that archive; null when idle
     * @param ?string $phase Current {@see RestorePhase} value; null when idle
     * @param ?string $phaseStartedAt ISO-8601 instant the current phase began; null when there is no phase
     * @param ?string $startedAt ISO-8601 start time of the run; null when idle
     * @param ?string $finishedAt ISO-8601 finish time of the last run; null while it runs
     * @param ?string $outcome Terminal {@see BackupStatus} value; null until one finishes
     * @param ?string $failureReason Why the last run failed; null when it succeeded or never ran
     * @param ?int $estimatedSeconds How long the run is expected to take; null when there is no history for it
     * @param bool $rehydrateComplete Whether every process confirmed re-reading the replaced database
     * @param list<string> $rehydrateProblems Processes that failed to re-read or never answered
     * @param bool $databaseTouched Whether the run reached its first destructive step
     */
    public function __construct(
        public readonly bool $running,
        public readonly ?string $backupId,
        public readonly ?string $scope,
        public readonly ?string $phase,
        public readonly ?string $phaseStartedAt,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $outcome,
        public readonly ?string $failureReason,
        public readonly ?int $estimatedSeconds,
        public readonly bool $rehydrateComplete,
        public readonly array $rehydrateProblems,
        public readonly bool $databaseTouched,
    ) {
    }

    /**
     * Photographs the restore runtime row as the frame the initiator is sent.
     *
     * @param RestoreRuntime $runtime Restore runtime singleton view
     * @return static Frame carrying that row's current values
     */
    public static function fromRuntime(RestoreRuntime $runtime): static
    {
        return new static(
            running: $runtime->running,
            backupId: $runtime->backupId,
            scope: $runtime->scope,
            phase: $runtime->phase,
            phaseStartedAt: $runtime->phaseStartedAt,
            startedAt: $runtime->startedAt,
            finishedAt: $runtime->finishedAt,
            outcome: $runtime->outcome,
            failureReason: $runtime->failureReason,
            estimatedSeconds: $runtime->estimatedSeconds,
            rehydrateComplete: $runtime->rehydrateComplete,
            rehydrateProblems: $runtime->rehydrateProblems,
            databaseTouched: $runtime->databaseTouched,
        );
    }

    /**
     * @return array<string, mixed> DTO data as array
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

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a flag the frame is defined by is absent or not a boolean
     */
    public static function fromArray(array $data): static
    {
        return new static(
            running: self::requireBool($data, self::running),
            backupId: self::optionalString($data, self::backupId),
            scope: self::optionalString($data, self::scope),
            phase: self::optionalString($data, self::phase),
            phaseStartedAt: self::optionalString($data, self::phaseStartedAt),
            startedAt: self::optionalString($data, self::startedAt),
            finishedAt: self::optionalString($data, self::finishedAt),
            outcome: self::optionalString($data, self::outcome),
            failureReason: self::optionalString($data, self::failureReason),
            estimatedSeconds: self::optionalInt($data, self::estimatedSeconds),
            rehydrateComplete: self::requireBool($data, self::rehydrateComplete),
            rehydrateProblems: self::problemList($data),
            databaseTouched: self::requireBool($data, self::databaseTouched),
        );
    }

    /**
     * Reads the re-hydrate problem lines out of a frame payload.
     *
     * An absent list is an empty one rather than a broken frame: a run that had no problems
     * says so by carrying none, and that is the shape every successful restore has.
     *
     * @param array<string, mixed> $data Frame payload
     * @return list<string> Problem lines, empty when the frame carried none
     */
    private static function problemList(array $data): array
    {
        $value = $data[self::rehydrateProblems] ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(strval(...), $value));
    }
}

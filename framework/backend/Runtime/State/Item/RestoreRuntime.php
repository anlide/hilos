<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\RestorePhase;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RestoreRuntimeRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Utils\Helpers\UtcMoment;
use LogicException;

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

    private const int MOMENT_MAX_BYTES = 32;

    /** Fixed row schema; variable values live in segmented pages. */
    private const array FIELD_TYPES = [
        self::running => 'bool',
        self::backupId => '?text',
        self::scope => '?text',
        self::phase => '?text',
        self::phaseStartedAt => '?moment',
        self::startedAt => '?moment',
        self::finishedAt => '?moment',
        self::outcome => '?text',
        self::failureReason => '?text',
        self::estimatedSeconds => '?int',
        self::rehydrateComplete => 'bool',
        self::rehydrateProblems => 'list',
        self::databaseTouched => 'bool',
    ];

    /** Application property defaults, not defaults for missing required incoming fields. */
    private const array FIELD_DEFAULTS = [
        self::running => false,
        self::backupId => null,
        self::scope => null,
        self::phase => null,
        self::phaseStartedAt => null,
        self::startedAt => null,
        self::finishedAt => null,
        self::outcome => null,
        self::failureReason => null,
        self::estimatedSeconds => null,
        self::rehydrateComplete => false,
        self::rehydrateProblems => [],
        self::databaseTouched => false,
    ];

    /** Exactly the fields accepted by the legacy patch door. */
    private const array PATCH_FIELDS = [
        self::running => true,
        self::backupId => true,
        self::scope => true,
        self::phase => true,
        self::phaseStartedAt => true,
        self::startedAt => true,
        self::finishedAt => true,
        self::outcome => true,
        self::failureReason => true,
        self::estimatedSeconds => true,
        self::rehydrateComplete => true,
        self::rehydrateProblems => true,
        self::databaseTouched => true,
    ];

    /** Whether a restore is currently running. */
    public bool $running {
        get => $this->rowValues()[self::running] ?? self::FIELD_DEFAULTS[self::running];
        set {
            $this->setRowValue(self::running, $value);
        }
    }

    /** Id of the backup being restored, or null when idle. */
    public ?string $backupId {
        get => ($this->rowValues()[self::backupId] ?? null)?->toNative();
        set {
            $this->setRowValue(self::backupId, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Scope value of the backup being restored, or null when idle. */
    public ?string $scope {
        get => ($this->rowValues()[self::scope] ?? null)?->toNative();
        set {
            $this->setRowValue(self::scope, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Current {@see RestorePhase} value, or null when idle. */
    public ?string $phase {
        get => ($this->rowValues()[self::phase] ?? null)?->toNative();
        set {
            $this->setRowValue(self::phase, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** ISO-8601 UTC (Z) instant the current phase began, or null when there is no phase. */
    public ?string $phaseStartedAt {
        get => ($this->rowValues()[self::phaseStartedAt] ?? null)?->toNative();
        set {
            $this->setRowValue(self::phaseStartedAt, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** ISO-8601 UTC (Z) start time of the restore in progress, or null when idle. */
    public ?string $startedAt {
        get => ($this->rowValues()[self::startedAt] ?? null)?->toNative();
        set {
            $this->setRowValue(self::startedAt, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** ISO-8601 UTC (Z) finish time of the last restore, or null while running or idle. */
    public ?string $finishedAt {
        get => ($this->rowValues()[self::finishedAt] ?? null)?->toNative();
        set {
            $this->setRowValue(self::finishedAt, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Terminal {@see BackupStatus} value of the last restore, or null. */
    public ?string $outcome {
        get => ($this->rowValues()[self::outcome] ?? null)?->toNative();
        set {
            $this->setRowValue(self::outcome, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Why the last restore failed, or null when it succeeded or never ran. */
    public ?string $failureReason {
        get => ($this->rowValues()[self::failureReason] ?? null)?->toNative();
        set {
            $this->setRowValue(self::failureReason, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /**
     * How long the restore is expected to take in seconds, or null when no restore of this scope
     * has been recorded yet. It sits beside the phase and the instant it began because those three
     * are what a progress bar is drawn from - the percentage and the time left are computed by
     * whoever shows them, which is why this row is written on a phase change and never on a timer.
     */
    public ?int $estimatedSeconds {
        get => $this->rowValues()[self::estimatedSeconds] ?? self::FIELD_DEFAULTS[self::estimatedSeconds];
        set {
            $this->setRowValue(self::estimatedSeconds, $value);
        }
    }

    /** Whether every process confirmed re-reading the replaced database (HIL-436). */
    public bool $rehydrateComplete {
        get => $this->rowValues()[self::rehydrateComplete] ?? self::FIELD_DEFAULTS[self::rehydrateComplete];
        set {
            $this->setRowValue(self::rehydrateComplete, $value);
        }
    }

    /**
     * @var list<string> Processes that failed to re-read or never answered, one line each
     *
     * Empty is not the same as good: it is also what an idle row says. The barrier's verdict is
     * {@see self::$rehydrateComplete}; this names who is behind a negative one, so the operator
     * knows which process to look at instead of being told only that the node stayed closed.
     */
    public array $rehydrateProblems {
        get => isset($this->rowValues()[self::rehydrateProblems])
            ? $this->rowValues()[self::rehydrateProblems]->toNative()
            : self::FIELD_DEFAULTS[self::rehydrateProblems];
        set {
            $this->setRowValue(self::rehydrateProblems, RtRowValues::fromNative($value));
        }
    }

    /**
     * @var bool Whether the run reached its first destructive step
     *
     * The difference between "the database is untouched, nothing was lost" and "the database may be
     * half-overwritten", which is the first thing an operator needs to know from a failed restore.
     */
    public bool $databaseTouched {
        get => $this->rowValues()[self::databaseTouched] ?? self::FIELD_DEFAULTS[self::databaseTouched];
        set {
            $this->setRowValue(self::databaseTouched, $value);
        }
    }

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
        $instance->phaseStartedAt = $instance->phaseStartedAt === null ? null : UtcMoment::normalize($instance->phaseStartedAt);
        $instance->startedAt = self::optionalString($row, self::startedAt);
        $instance->startedAt = $instance->startedAt === null ? null : UtcMoment::normalize($instance->startedAt);
        $instance->finishedAt = self::optionalString($row, self::finishedAt);
        $instance->finishedAt = $instance->finishedAt === null ? null : UtcMoment::normalize($instance->finishedAt);
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
        if (array_key_exists(self::phaseStartedAt, $diff)) {
            $moment = self::patchOptionalString($diff, self::phaseStartedAt, $this->phaseStartedAt);
            $this->phaseStartedAt = $moment === null ? null : UtcMoment::normalize($moment);
        }
        if (array_key_exists(self::startedAt, $diff)) {
            $moment = self::patchOptionalString($diff, self::startedAt, $this->startedAt);
            $this->startedAt = $moment === null ? null : UtcMoment::normalize($moment);
        }
        if (array_key_exists(self::finishedAt, $diff)) {
            $moment = self::patchOptionalString($diff, self::finishedAt, $this->finishedAt);
            $this->finishedAt = $moment === null ? null : UtcMoment::normalize($moment);
        }
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

    /** @return RtRowCodec Segmented codec preserving this row's nested-value contract */
    public function rowCodec(): RtRowCodec
    {
        return new RestoreRuntimeRowCodec();
    }

    /**
     * Prepares an unpublished row; the committed-root layer supplies its baseline later.
     *
     * @param RtRowReader $in Segmented full row
     * @return Generator<int, RtWork, int, static> Fully validated candidate
     * @throws InvalidFormatException When a field violates the existing row contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public static function fromValues(RtRowReader $in): Generator
    {
        $prepared = yield from self::prepareValues($in, null);
        yield new RtWork();
        $instance = new static();
        $instance->replaceRowValues($prepared);

        return $instance;
    }

    /**
     * Every accepted field is validated before the fixed descriptor is replaced.
     *
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Atomic candidate patch
     * @throws InvalidFormatException When a changed field violates the existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function patchValues(RtRowReader $diff): Generator
    {
        $prepared = yield from self::prepareValues($diff, $this->rowValues());
        yield new RtWork();
        $this->replaceRowValues($prepared);
    }

    /**
     * @param RtRowWriter $out Unpublished output in any Runtime arena
     * @return Generator<int, RtWork, int, void> Bounded nested-value export
     * @throws InvalidFormatException When a stored segmented value is malformed
     * @throws LogicException When the output has already closed
     */
    public function exportValues(RtRowWriter $out): Generator
    {
        return $this->frozenValues()->export($out);
    }

    /** @return array<string, mixed> Fixed property defaults in codec export order */
    protected function rowFieldDefaults(): array
    {
        return self::FIELD_DEFAULTS;
    }

    /**
     * @param RtRowReader $input Segmented full row or diff
     * @param ?array<string, RtRowValues|int|float|bool|null> $current Fixed descriptor, null for restore
     * @return Generator<int, RtWork, int, array<string, RtRowValues|int|float|bool|null>> Validated fixed descriptor
     * @throws InvalidFormatException When a field violates its existing contract
     * @throws LogicException When a writer lifetime is violated
     */
    private static function prepareValues(RtRowReader $input, ?array $current): Generator
    {
        $prepared = $current ?? [];
        foreach (self::FIELD_TYPES as $key => $type) {
            yield new RtWork();
            if ($current !== null && !isset(self::PATCH_FIELDS[$key])) {
                continue;
            }
            $value = yield from $input->field($key);
            if ($value === null) {
                if ($current !== null) {
                    continue;
                }
                if (str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                throw new InvalidFormatException('Runtime row is missing required key ' . $key);
            }
            $reader = $value->reader();
            if ($reader->kind() === RtRowKind::Scalar) {
                $scalar = yield from $reader->scalar();
                if ($scalar === null && str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                $scalarType = ltrim($type, '?');
                if (($scalarType === 'int' && is_int($scalar)) || ($scalarType === 'bool' && is_bool($scalar))) {
                    $prepared[$key] = $scalar;
                    continue;
                }
                if ($scalarType === 'float' && (is_int($scalar) || is_float($scalar))) {
                    $prepared[$key] = (float)$scalar;
                    continue;
                }
            }
            if (in_array($type, ['list', '?list', 'array'], true)) {
                $prepared[$key] = yield from self::prepareList($reader);
                continue;
            }
            if ($reader->kind() !== RtRowKind::Text || !in_array($type, ['text', '?text', 'moment', '?moment'], true)) {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            if ($type === '?moment') {
                $moment = yield from $reader->boundedText(self::MOMENT_MAX_BYTES);
                $remaining = strlen($moment);
                while ($remaining > 0) {
                    $remaining -= yield new RtWork($remaining);
                }
                $prepared[$key] = yield from RtRowWriter::encode(
                    UtcMoment::normalize($moment), $input->arena(), RtRowRole::Candidate,
                );
                continue;
            }
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        return $prepared;
    }

    /**
     * Strict string lists retain their order and duplicates without a native list.
     *
     * @param RtRowReader $input Incoming array
     * @return Generator<int, RtWork, int, RtRowValues> Validated segmented list
     * @throws InvalidFormatException When a list key or element violates the existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    private static function prepareList(RtRowReader $input): Generator
    {
        $cursor = $input->entries();
        $writer = new RtRowWriter($input->arena(), RtRowRole::Candidate);
        $position = 0;
        while (($entry = yield from $cursor->advance()) !== null) {
            if ($entry->key->reader()->kind() !== RtRowKind::Scalar
                || (yield from $entry->key->reader()->scalar()) !== $position
                || $entry->value->reader()->kind() !== RtRowKind::Text) {
                throw new InvalidFormatException('Runtime row carries no list of strings');
            }
            $value = yield from RtRowWriter::copy($entry->value->reader(), $input->arena());
            yield from $writer->write($position++, $value);
        }

        return yield from $writer->finish();
    }
}

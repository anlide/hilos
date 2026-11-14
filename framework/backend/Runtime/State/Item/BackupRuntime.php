<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Backup\BackupPhase;
use Hilos\Backup\BackupProgress;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\BackupRuntimeRowCodec;
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
    public const string percent = 'percent';
    public const string remainingSeconds = 'remainingSeconds';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::running => 'bool',
        self::currentBackupId => '?text',
        self::scope => '?text',
        self::startedAt => '?moment',
        self::phase => '?text',
        self::phaseStartedAt => '?moment',
        self::estimatedSeconds => '?int',
        self::percent => '?int',
        self::remainingSeconds => '?int',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::running => false,
        self::currentBackupId => null,
        self::scope => null,
        self::startedAt => null,
        self::phase => null,
        self::phaseStartedAt => null,
        self::estimatedSeconds => null,
        self::percent => null,
        self::remainingSeconds => null,
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::running => true,
        self::currentBackupId => true,
        self::scope => true,
        self::startedAt => true,
        self::phase => true,
        self::phaseStartedAt => true,
        self::estimatedSeconds => true,
        self::percent => true,
        self::remainingSeconds => true,
    ];

    private const int MOMENT_MAX_BYTES = 32;

    /** Whether a backup is currently running. */
    public bool $running {
        get => $this->rowValues()[self::running] ?? self::FIELD_DEFAULTS[self::running];
        set {
            $this->setRowValue(self::running, $value);
        }
    }

    /** Id of the backup in progress, or null when idle. */
    public ?string $currentBackupId {
        get => ($this->rowValues()[self::currentBackupId] ?? null)?->toNative();
        set {
            $this->setRowValue(self::currentBackupId, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Scope value of the backup in progress, or null when idle. */
    public ?string $scope {
        get => ($this->rowValues()[self::scope] ?? null)?->toNative();
        set {
            $this->setRowValue(self::scope, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** ISO-8601 UTC (Z) start time of the backup in progress, or null when idle. */
    public ?string $startedAt {
        get => ($this->rowValues()[self::startedAt] ?? null)?->toNative();
        set {
            $this->setRowValue(self::startedAt, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Phase value ({@see BackupPhase}) the run is in, or null when idle or not yet reported. */
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

    /**
     * How long the run is expected to take in seconds, or null when there is no history to
     * estimate from. It is the budget the two figures below are measured against rather than
     * something a reader recomputes: the agent does that arithmetic here and writes the answer,
     * so the row is refreshed on a timer while an estimated run is in flight.
     */
    public ?int $estimatedSeconds {
        get => $this->rowValues()[self::estimatedSeconds] ?? self::FIELD_DEFAULTS[self::estimatedSeconds];
        set {
            $this->setRowValue(self::estimatedSeconds, $value);
        }
    }

    /**
     * How far along the run is, 0..99, or null when the run cannot be estimated.
     *
     * Never a hundred while the row still says running: a full bar under a run that has not
     * finished is the one reading an operator cannot act on ({@see BackupProgress::percent()}).
     */
    public ?int $percent {
        get => $this->rowValues()[self::percent] ?? self::FIELD_DEFAULTS[self::percent];
        set {
            $this->setRowValue(self::percent, $value);
        }
    }

    /**
     * How many seconds the run is still expected to take, or null when it cannot be estimated.
     *
     * Negative once the estimate is spent, which is the whole reason it is not clamped: a run
     * that outlived its estimate and one that is about to finish must not read the same.
     */
    public ?int $remainingSeconds {
        get => $this->rowValues()[self::remainingSeconds] ?? self::FIELD_DEFAULTS[self::remainingSeconds];
        set {
            $this->setRowValue(self::remainingSeconds, $value);
        }
    }

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
        $instance->startedAt = $instance->startedAt === null ? null : UtcMoment::normalize($instance->startedAt);
        $instance->phase = self::optionalString($row, self::phase);
        $instance->phaseStartedAt = self::optionalString($row, self::phaseStartedAt);
        $instance->phaseStartedAt = $instance->phaseStartedAt === null ? null : UtcMoment::normalize($instance->phaseStartedAt);
        $instance->estimatedSeconds = self::optionalInt($row, self::estimatedSeconds);
        $instance->percent = self::optionalInt($row, self::percent);
        $instance->remainingSeconds = self::optionalInt($row, self::remainingSeconds);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this singleton.
     *
     * The backup table's progress bar is built out of this item, so without the diffs every
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
        if (array_key_exists(self::startedAt, $diff)) {
            $moment = self::patchOptionalString($diff, self::startedAt, $this->startedAt);
            $this->startedAt = $moment === null ? null : UtcMoment::normalize($moment);
        }
        $this->phase = self::patchOptionalString($diff, self::phase, $this->phase);
        if (array_key_exists(self::phaseStartedAt, $diff)) {
            $moment = self::patchOptionalString($diff, self::phaseStartedAt, $this->phaseStartedAt);
            $this->phaseStartedAt = $moment === null ? null : UtcMoment::normalize($moment);
        }
        $this->estimatedSeconds = self::patchOptionalInt($diff, self::estimatedSeconds, $this->estimatedSeconds);
        $this->percent = self::patchOptionalInt($diff, self::percent, $this->percent);
        $this->remainingSeconds = self::patchOptionalInt($diff, self::remainingSeconds, $this->remainingSeconds);
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
            self::percent => $this->percent,
            self::remainingSeconds => $this->remainingSeconds,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new BackupRuntimeRowCodec();
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
     * @return Generator<int, RtWork, int, void> Bounded scalar/text export
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
                    UtcMoment::normalize($moment),
                    $input->arena(),
                    RtRowRole::Candidate,
                );
                continue;
            }
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        return $prepared;
    }
}

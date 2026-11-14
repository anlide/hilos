<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Error;
use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\HilosStatusComponentRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Status\StatusComponentDefinition;
use LogicException;

/** Current counts only; durable Status facts and deadlines belong to the incident tables. */
final class HilosStatusComponent extends RtState
{
    public const string RT_COLLECTION = 'hilosStatusComponents';

    public const string componentKey = 'componentKey';
    public const string expectedInstances = 'expectedInstances';
    public const string liveInstances = 'liveInstances';
    public const string observedAt = 'observedAt';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::componentKey => 'text',
        self::expectedInstances => 'int',
        self::liveInstances => '?int',
        self::observedAt => '?int',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::liveInstances => null,
        self::observedAt => null,
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::componentKey => true,
        self::expectedInstances => true,
        self::liveInstances => true,
        self::observedAt => true,
    ];

    /** The bound declared by StatusComponentDefinition::KEY_PATTERN. */
    private const int COMPONENT_KEY_MAX_BYTES = 128;

    private(set) string $componentKey {
        get {
            if (!array_key_exists(self::componentKey, $this->rowValues())) {
                throw new Error('Typed property ' . self::class . '::$componentKey must not be accessed before initialization');
            }

            return $this->rowValues()[self::componentKey]->toNative();
        }
        set {
            $this->setRowValue(self::componentKey, RtRowValues::fromNative($value));
        }
    }

    public int $expectedInstances {
        get {
            if (!array_key_exists(self::expectedInstances, $this->rowValues())) {
                throw new Error('Typed property ' . self::class . '::$expectedInstances must not be accessed before initialization');
            }

            return $this->rowValues()[self::expectedInstances];
        }
        set {
            $this->setRowValue(self::expectedInstances, $value);
        }
    }

    public ?int $liveInstances {
        get => $this->rowValues()[self::liveInstances] ?? self::FIELD_DEFAULTS[self::liveInstances];
        set {
            $this->setRowValue(self::liveInstances, $value);
        }
    }

    public ?int $observedAt {
        get => $this->rowValues()[self::observedAt] ?? self::FIELD_DEFAULTS[self::observedAt];
        set {
            $this->setRowValue(self::observedAt, $value);
        }
    }

    /**
     * @param string $componentKey Stable component id
     * @param int $expectedInstances Expected slots, at least one
     * @return static Unknown component counts
     * @throws InvalidFormatException When the key or expected count is invalid
     */
    public static function create(string $componentKey, int $expectedInstances): static
    {
        self::validate($componentKey, $expectedInstances, null);
        $instance = new static();
        $instance->componentKey = $componentKey;
        $instance->expectedInstances = $expectedInstances;
        $instance->markRtSyncBaseline();
        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized current counts
     * @return static Hydrated counts without invented observations
     * @throws InvalidFormatException When fields have invalid types or counts
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->componentKey = self::requireString($row, self::componentKey);
        $instance->expectedInstances = self::requireInt($row, self::expectedInstances);
        $instance->liveInstances = self::optionalInt($row, self::liveInstances);
        $instance->observedAt = self::optionalInt($row, self::observedAt);
        self::validate($instance->componentKey, $instance->expectedInstances, $instance->liveInstances);
        $instance->markRtSyncBaseline();
        return $instance;
    }

    /** @return string Runtime collection key */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /** @return string Immutable component key */
    public function getId(): string
    {
        return $this->componentKey;
    }

    /** @return array<string, mixed> The four-field runtime sync row */
    public function toArray(): array
    {
        return [
            self::componentKey => $this->componentKey,
            self::expectedInstances => $this->expectedInstances,
            self::liveInstances => $this->liveInstances,
            self::observedAt => $this->observedAt,
        ];
    }

    /**
     * @param array<string, mixed> $diff Changed fields only
     * @throws InvalidFormatException When the key changes or counts/types are invalid
     */
    public function applyDiff(array $diff): void
    {
        $componentKey = self::patchString($diff, self::componentKey, $this->componentKey);
        $expectedInstances = self::patchInt($diff, self::expectedInstances, $this->expectedInstances);
        $liveInstances = self::patchOptionalInt($diff, self::liveInstances, $this->liveInstances);
        $observedAt = self::patchOptionalInt($diff, self::observedAt, $this->observedAt);
        if ($componentKey !== $this->componentKey) {
            throw new InvalidFormatException('A Status component key cannot change.');
        }
        self::validate($componentKey, $expectedInstances, $liveInstances);
        $this->expectedInstances = $expectedInstances;
        $this->liveInstances = $liveInstances;
        $this->observedAt = $observedAt;
    }

    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosStatusComponentRowCodec();
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

    /** @return array<string, mixed> Codec order; the first two fields are always initialized */
    protected function rowFieldDefaults(): array
    {
        return [self::componentKey => null, self::expectedInstances => null] + self::FIELD_DEFAULTS;
    }

    /**
     * @param string $componentKey Stable component key
     * @param int $expectedInstances Expected slots
     * @param ?int $liveInstances Observed live slots, when known
     * @throws InvalidFormatException When a key or count is outside its domain
     */
    private static function validate(string $componentKey, int $expectedInstances, ?int $liveInstances): void
    {
        if (preg_match(StatusComponentDefinition::KEY_PATTERN, $componentKey) !== 1
            || $expectedInstances < 1 || ($liveInstances !== null && $liveInstances < 0)) {
            throw new InvalidFormatException('Status counts require a valid component key, positive expected slots and nonnegative live slots.');
        }
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
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }
        $componentKey = yield from $prepared[self::componentKey]->reader()->boundedText(self::COMPONENT_KEY_MAX_BYTES);
        if ($current !== null) {
            $previousKey = yield from $current[self::componentKey]->reader()->boundedText(self::COMPONENT_KEY_MAX_BYTES);
            if ($componentKey !== $previousKey) {
                throw new InvalidFormatException('A Status component key cannot change.');
            }
        }
        $remaining = strlen($componentKey);
        while ($remaining > 0) {
            $remaining -= yield new RtWork($remaining);
        }
        self::validate($componentKey, $prepared[self::expectedInstances], $prepared[self::liveInstances] ?? null);

        return $prepared;
    }
}

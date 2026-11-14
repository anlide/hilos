<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Cluster\Probe\FleetProbeAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\HilosProbeFleetStatusRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosProbeFleetStatuses;
use LogicException;

/**
 * Runtime status of one member of the cluster probe fleet: what it has done, and what it can see.
 *
 * One row per fleet member ({@see FleetProbeAgent}), written by that member alone and replicated
 * to every other node. The fleet is what makes cross-node RT worth watching on a cluster stand —
 * several nodes writing rows of one collection, each owning its own — and the acceptance
 * scenarios read these rows back on nodes that never wrote them.
 *
 * Framework-owned runtime state mounted for every project ({@see HilosProbeFleetStatuses}), and
 * silent until a probe writes: nothing but the fleet does, and the fleet starts only on a
 * clustered node of a non-production environment.
 */
final class HilosProbeFleetStatus extends RtState
{
    /** Runtime collection key mounted by the framework and used for RT sync. */
    public const string RT_COLLECTION = 'hilosProbeFleetStatuses';

    public const string workerIndex = 'workerIndex';
    public const string jobsDone = 'jobsDone';
    public const string rowsSeen = 'rowsSeen';
    public const string updatedAt = 'updatedAt';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::workerIndex => 'text',
        self::jobsDone => 'int',
        self::rowsSeen => 'int',
        self::updatedAt => 'int',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::workerIndex => '',
        self::jobsDone => 0,
        self::rowsSeen => 0,
        self::updatedAt => 0,
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::jobsDone => true,
        self::rowsSeen => true,
        self::updatedAt => true,
    ];

    /** Fleet member index this row belongs to, and its row id. */
    private(set) string $workerIndex {
        get => isset($this->rowValues()[self::workerIndex])
            ? $this->rowValues()[self::workerIndex]->toNative()
            : self::FIELD_DEFAULTS[self::workerIndex];
        set {
            $this->setRowValue(self::workerIndex, RtRowValues::fromNative($value));
        }
    }

    /** Synthetic jobs this member has finished since it started. */
    public int $jobsDone {
        get => $this->rowValues()[self::jobsDone] ?? self::FIELD_DEFAULTS[self::jobsDone];
        set {
            $this->setRowValue(self::jobsDone, $value);
        }
    }

    /** How many rows of this collection the member itself could see when it last reported. */
    public int $rowsSeen {
        get => $this->rowValues()[self::rowsSeen] ?? self::FIELD_DEFAULTS[self::rowsSeen];
        set {
            $this->setRowValue(self::rowsSeen, $value);
        }
    }

    /** Unix time of the last report. */
    public int $updatedAt {
        get => $this->rowValues()[self::updatedAt] ?? self::FIELD_DEFAULTS[self::updatedAt];
        set {
            $this->setRowValue(self::updatedAt, $value);
        }
    }

    /**
     * @param string $workerIndex Fleet member index
     * @return static Fresh status row
     */
    public static function create(string $workerIndex): static
    {
        $instance = new static();
        $instance->workerIndex = $workerIndex;
        $instance->updatedAt = time();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated status row
     * @throws InvalidFormatException When the row is missing a field the status is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->workerIndex = self::requireString($row, self::workerIndex);
        $instance->jobsDone = self::requireInt($row, self::jobsDone);
        $instance->rowsSeen = self::requireInt($row, self::rowsSeen);
        $instance->updatedAt = self::requireInt($row, self::updatedAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for the probe fleet status rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->jobsDone = self::patchInt($diff, self::jobsDone, $this->jobsDone);
        $this->rowsSeen = self::patchInt($diff, self::rowsSeen, $this->rowsSeen);
        $this->updatedAt = self::patchInt($diff, self::updatedAt, $this->updatedAt);
    }

    /**
     * @return string Runtime row id, the fleet member index
     */
    public function getId(): string
    {
        return $this->workerIndex;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::workerIndex => $this->workerIndex,
            self::jobsDone => $this->jobsDone,
            self::rowsSeen => $this->rowsSeen,
            self::updatedAt => $this->updatedAt,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosProbeFleetStatusRowCodec();
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
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        return $prepared;
    }
}

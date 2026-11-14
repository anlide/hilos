<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\Row\TableLagRuntimeRowCodec;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/**
 * TableLagRuntime - the singleton runtime state of the test-only table lag (HIL-1020).
 *
 * Two artificial delays a test puts on a browser table, one per frame it wants to watch arrive
 * late: the window that answers a changed viewport, and the counts beside the filter options.
 * The row skeleton and the counts are both decided by how long those frames take, and on a
 * development box they take tens of milliseconds - too fast for either state to be seen. This
 * row is what makes them visible, to an e2e and to the eye alike.
 *
 * Framework-owned and mounted for every project, like {@see ProtectedModeRuntime}, and written
 * the same way: by the daemon master of the node, which answers `test:table:lag` itself. The row
 * is node-local and never travels the mesh. On production it stays at zero for good - the command
 * socket refuses every `test:` command there, so nothing can write it.
 */
final class TableLagRuntime extends RtState
{
    /** Runtime item alias the framework mounts and the RT sync carries. */
    public const string RT_ITEM = 'hilosTableLagRuntime';

    /** Stable singleton row id. */
    public const string ID = 'runtime';

    public const string windowMs = 'windowMs';
    public const string facetsMs = 'facetsMs';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::windowMs => 'int',
        self::facetsMs => 'int',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::windowMs => 0,
        self::facetsMs => 0,
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::windowMs => true,
        self::facetsMs => true,
    ];

    /** Milliseconds an incoming table viewport change is held before it is answered; 0 means no lag. */
    public int $windowMs {
        get => $this->rowValues()[self::windowMs] ?? self::FIELD_DEFAULTS[self::windowMs];
        set {
            $this->setRowValue(self::windowMs, $value);
        }
    }

    /** Milliseconds a facet count is held before it is counted and sent; 0 means no lag. */
    public int $facetsMs {
        get => $this->rowValues()[self::facetsMs] ?? self::FIELD_DEFAULTS[self::facetsMs];
        set {
            $this->setRowValue(self::facetsMs, $value);
        }
    }

    /**
     * Creates the singleton row with both lags off.
     *
     * @return static Table lag runtime state with no lag
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
     * @throws InvalidFormatException When the row lost either lag or carries one as another type
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->windowMs = self::requireInt($row, self::windowMs);
        $instance->facetsMs = self::requireInt($row, self::facetsMs);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this singleton.
     *
     * The master writes the row and the workers serve the tables, so without the diffs no worker
     * would ever hold a frame back.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a lag as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->windowMs = self::patchInt($diff, self::windowMs, $this->windowMs);
        $this->facetsMs = self::patchInt($diff, self::facetsMs, $this->facetsMs);
    }

    /**
     * @return string Runtime collection key for the table lag singleton
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
            self::windowMs => $this->windowMs,
            self::facetsMs => $this->facetsMs,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new TableLagRuntimeRowCodec();
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

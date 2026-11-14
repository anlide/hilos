<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\AdminViewMode\AdminViewModeStartup;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\AdminViewModeRuntimeRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/**
 * AdminViewModeRuntime - the singleton runtime state of the admin view mode of a node (HIL-1249).
 *
 * Whether a non-admin may open the admin section on this node to look and change nothing. The
 * variable decides it only at the start - the environment of a living process cannot change - so
 * the answer lives here, where a lever can change it too.
 *
 * Framework-owned and mounted for every project, like {@see TableRefusalRuntime}, and written the
 * same way: only by the daemon master of the node - once at its start ({@see AdminViewModeStartup})
 * and in answer to `test:admin-view-mode` on a stand. The workers read it: a worker is a process of
 * its own, handed the row by the master when it comes up and kept current through the RT sync.
 * The row is node-local and never travels the mesh. It stays inert (false) while the mode is off.
 */
final class AdminViewModeRuntime extends RtState
{
    /** Runtime item alias the framework mounts and the RT sync carries. */
    public const string RT_ITEM = 'hilosAdminViewModeRuntime';

    /** Stable singleton row id. */
    public const string ID = 'runtime';

    public const string enabled = 'enabled';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::enabled => 'bool',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::enabled => false,
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::enabled => true,
    ];

    /** Whether a non-admin may open the admin section on this node to look. */
    public bool $enabled {
        get => $this->rowValues()[self::enabled] ?? self::FIELD_DEFAULTS[self::enabled];
        set {
            $this->setRowValue(self::enabled, $value);
        }
    }

    /**
     * Creates the singleton row with the mode off.
     *
     * @return static Admin view mode runtime state with the mode off
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
     * @throws InvalidFormatException When the row lost the mode or carries it as another type
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->enabled = self::requireBool($row, self::enabled);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this singleton.
     *
     * The master writes the row and the workers answer the viewers, so without the diffs a lever
     * pulled on a living node would reach no worker.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries the mode as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->enabled = self::patchBool($diff, self::enabled, $this->enabled);
    }

    /**
     * @return string Runtime collection key for the admin view mode singleton
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
            self::enabled => $this->enabled,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new AdminViewModeRuntimeRowCodec();
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

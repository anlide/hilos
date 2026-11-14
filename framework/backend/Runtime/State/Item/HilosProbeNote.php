<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Cluster\Probe\RtSetProbeAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\HilosProbeNoteRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosProbeNotes;
use LogicException;

/**
 * One note of the cluster set probe: a line of text in the set of the node it belongs to.
 *
 * The collection is cut into sets by the node ({@see self::SET_VIA}), and each node's set probe
 * ({@see RtSetProbeAgent}) owns the set of its own node - which is what lets a cluster run show a claim over a set
 * holding across nodes (HIL-1116): a node writes its own set, is refused another node's, and a
 * node cut off while a set was written gets the row when the set is handed over.
 *
 * Only the text is ever rewritten. The node a note belongs to is set when it is created, since
 * moving a row into another set is the right of the owner of the whole collection, and the
 * stand has none.
 *
 * Framework-owned runtime state mounted for every project ({@see HilosProbeNotes}), and silent
 * until a probe writes: nothing but the set probe does, and it starts only on a clustered node
 * of a non-production environment.
 */
final class HilosProbeNote extends RtState
{
    /** Runtime collection key mounted by the framework and used for RT sync. */
    public const string RT_COLLECTION = 'hilosProbeNotes';

    public const string noteId = 'noteId';
    public const string nodeId = 'nodeId';
    public const string text = 'text';

    /** The set a note is in is the node it belongs to. */
    public const string SET_VIA = self::nodeId;

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::noteId => 'text',
        self::nodeId => 'text',
        self::text => 'text',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::noteId => '',
        self::nodeId => '',
        self::text => '',
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::text => true,
    ];

    /** Note id, and its row id. */
    private(set) string $noteId {
        get => isset($this->rowValues()[self::noteId]) ? $this->rowValues()[self::noteId]->toNative() : self::FIELD_DEFAULTS[self::noteId];
        set {
            $this->setRowValue(self::noteId, RtRowValues::fromNative($value));
        }
    }

    /** Node whose set the note is in. */
    private(set) string $nodeId {
        get => isset($this->rowValues()[self::nodeId]) ? $this->rowValues()[self::nodeId]->toNative() : self::FIELD_DEFAULTS[self::nodeId];
        set {
            $this->setRowValue(self::nodeId, RtRowValues::fromNative($value));
        }
    }

    /** Text of the note, the one field a write rewrites. */
    public string $text {
        get => isset($this->rowValues()[self::text]) ? $this->rowValues()[self::text]->toNative() : self::FIELD_DEFAULTS[self::text];
        set {
            $this->setRowValue(self::text, RtRowValues::fromNative($value));
        }
    }

    /**
     * @param string $noteId Note id, and the row id
     * @param string $nodeId Node whose set the note is born in
     * @param string $text Text of the note
     * @return static Fresh note row
     */
    public static function create(string $noteId, string $nodeId, string $text): static
    {
        $instance = new static();
        $instance->noteId = $noteId;
        $instance->nodeId = $nodeId;
        $instance->text = $text;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated note row
     * @throws InvalidFormatException When the row is missing a field the note is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->noteId = self::requireString($row, self::noteId);
        $instance->nodeId = self::requireString($row, self::nodeId);
        $instance->text = self::requireString($row, self::text);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for the probe notes.
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
        $this->text = self::patchString($diff, self::text, $this->text);
    }

    /**
     * @return string Runtime row id, the note id
     */
    public function getId(): string
    {
        return $this->noteId;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::noteId => $this->noteId,
            self::nodeId => $this->nodeId,
            self::text => $this->text,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosProbeNoteRowCodec();
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

<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException as FrameworkLogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\State\RtRowCodecNotSupportedException;
use Hilos\Runtime\Exception\State\RtStatePropertyNotFoundException;
use Hilos\Runtime\Exception\State\RtStateReadOnlyException;
use Hilos\Runtime\Exception\State\RtStateUnserializeException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\Row\RtFrozenRow;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowBinding;
use Hilos\Runtime\Row\RtRowDescriptor;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\Row\RtRowRoot;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\View\Actions\Item\RtActions;
use Hilos\TruthSource\RtTruthSourceRegistry;
use LogicException;
use WeakMap;

/**
 * Base class for runtime state objects.
 *
 * RtState is the single source of truth for runtime data (analogous to Object_).
 * Child classes should expose row fields as real typed properties; use PHP 8.4
 * asymmetric visibility (`private(set)`) for immutable ids.
 *
 * {@see sync()} broadcasts RT_SYNC_UPDATED for fields changed since {@see markRtSyncBaseline()} (worker sync only).
 * After inbound RT applyDiff, callers must call {@see markRtSyncBaseline()} so local sync() does not re-send stale diffs.
 */
abstract class RtState
{
    /**
     * Key of the {@see toArray()} field whose value names the set this row belongs to - the runtime
     * twin of the `_setVia` column an Entity declares ({@see Entity::META_SET_VIA}).
     *
     * A row class names its own field by that field's constant: `public const string SET_VIA =
     * self::userId;`. Empty means the collection is cut by no field, and a claim over a set on it is
     * refused by the topology check. The field holds a scalar, read as a string; null is a row in
     * nobody's set. There is no tree: the row carries its owner's key itself, and nothing climbs.
     */
    public const string SET_VIA = '';

    /** Process-local storage handle; legacy-only rows leave it absent. */
    private ?RtRowBinding $rowBinding = null;

    /** @var array<string, mixed> Explicit compatibility baseline for unconverted subclasses only */
    private array $legacyRtSyncBaseline = [];

    /**
     * The common binding resolves both values and baseline. Keeping this compatibility
     * accessor lets legacy rows and transactional undo use their existing sync semantics.
     *
     * @var array<string, mixed>|RtRowDescriptor
     */
    private array|RtRowDescriptor $rtSyncBaseline {
        get => $this->rowBinding?->baseline() ?? $this->legacyRtSyncBaseline;
        set {
            if ($value instanceof RtRowDescriptor) {
                $this->rowBinding->markBaseline($value);
            } else {
                $this->rowBinding?->markBaseline(null);
                $this->legacyRtSyncBaseline = $value;
            }
        }
    }

    /**
     * Protected constructor. Child classes must use static factory methods (e.g. fromRow).
     */
    protected function __construct()
    {
    }

    /**
     * Protected clone; a draft replaces its own binding without changing the live handle. The live
     * handle is settled first, so the draft starts from the values a published generation gave it.
     *
     * @throws InvalidFormatException When retained upload identities are malformed
     * @throws LogicException When a backing lease closed
     */
    protected function __clone()
    {
        if ($this->rowBinding !== null) {
            $this->rowBinding = $this->rowBinding->fork();
        }
    }

    /**
     * Public wakeup - prevent unserialization
     *
     * RtState instances cannot be safely unserialized as they represent
     * transient runtime state that should not persist across processes.
     *
     * @throws RtStateUnserializeException Always, unserialization not allowed
     */
    public function __wakeup(): void
    {
        throw new RtStateUnserializeException('RtState cannot be unserialized');
    }

    /**
     * Returns state as array for var_dump/print_r debug output.
     *
     * @return array<string, mixed> State fields as associative array
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Legacy fallback for dynamic property reads. Concrete state fields should be real typed properties.
     *
     * @param string $name Property name
     * @return mixed Property value (child classes must override; base always throws)
     * @throws RtStatePropertyNotFoundException When property does not exist (base implementation)
     */
    public function __get(string $name): mixed
    {
        $className = static::class;
        throw new RtStatePropertyNotFoundException("Property [{$name}] does not exist on {$className}");
    }

    /**
     * Legacy fallback for dynamic property writes. Concrete writable state fields should be real typed properties.
     *
     * @param string $name Property name
     * @param mixed $value New value
     *
     * @throws RtStateReadOnlyException When the property is not writable on this state class
     */
    public function __set(string $name, mixed $value): void
    {
        $className = static::class;
        throw new RtStateReadOnlyException(
            "Cannot set property [{$name}] on {$className}: RtState is read-only from outside."
        );
    }

    /**
     * RT collection key for sync. Empty string skips queue while still advancing the baseline.
     *
     * @return string Runtime collection key, or an empty string to skip sync enqueue
     */
    public static function getRtCollectionKey(): string
    {
        return '';
    }

    /**
     * @internal The codec of this row class, for a reader that has no row of it at hand yet: a
     * hand-over into an empty collection has nothing to ask {@see self::rowCodec()} of. Asked of a
     * blank instance, which no collection ever holds.
     *
     * @return RtRowCodec Codec the concrete row class declares
     * @throws RtRowCodecNotSupportedException When the row class has not migrated
     */
    final public static function rowCodecOfClass(): RtRowCodec
    {
        return new static()->rowCodec();
    }

    /**
     * Additive opt-in: legacy subclasses retain their synchronous doors. There is
     * deliberately no toArray/fromRow fallback in the resumable path.
     *
     * @return RtRowCodec Codec explicitly implemented by the concrete row
     * @throws RtRowCodecNotSupportedException When this row has not migrated
     */
    public function rowCodec(): RtRowCodec
    {
        throw new RtRowCodecNotSupportedException('Runtime row has no resumable codec: ' . static::class);
    }

    /**
     * Captures fixed descriptors only; no codec, native export or id materialization runs here.
     *
     * @return RtFrozenRow Immutable local values, baseline and handle incarnation
     * @throws RtRowCodecNotSupportedException When the row has no binding migration
     * @throws InvalidFormatException When retained upload identities are malformed
     * @throws LogicException When an internal backing lease closed
     */
    final public function freezeRow(): RtFrozenRow
    {
        if ($this->rowFieldDefaults() === []) {
            throw new RtRowCodecNotSupportedException('Runtime row has no descriptor binding: ' . static::class);
        }

        return $this->frozenValues();
    }

    /** @return bool Whether this row has a descriptor binding and a resumable codec, rather than legacy doors only */
    final public function hasRowBinding(): bool
    {
        return $this->rowFieldDefaults() !== [];
    }

    /**
     * Current row for a source fact or a sync signal: segmented for a row with a binding, so no
     * carrier materializes it, and the native compatibility row of a legacy-only state.
     *
     * The carrier references the row's own immutable values in the process arena, one entry per
     * field ({@see RtRowDescriptor::carry()}): nothing the row holds is copied, so one synchronous
     * step costs the number of fields, not the bytes of the row. Like {@see self::toArray()}, it
     * promises no refusal of its own: a malformed page or a closed backing lease here is a broken
     * Runtime invariant, not an outcome a writer handles.
     *
     * @return array<string, mixed>|RtRowValues Whole current row
     */
    final public function carriedRow(): array|RtRowValues
    {
        if ($this->rowFieldDefaults() === []) {
            return $this->toArray();
        }
        $this->rowValues();
        $out = new RtRowWriter(RtRowArena::process(), RtRowRole::Transient);
        RtRowValues::completed($this->rowBinding->current()->carry($out));

        return RtRowValues::completed($out->finish());
    }

    /**
     * @return static Unpublished clone with its own binding and immutable baseline
     * @throws RtRowCodecNotSupportedException When the row has no binding migration
     */
    final public function draftRow(): static
    {
        if ($this->rowFieldDefaults() === []) {
            throw new RtRowCodecNotSupportedException('Runtime row has no descriptor binding: ' . static::class);
        }
        $this->rowValues();

        return clone $this;
    }

    /**
     * @internal Publishes a validated draft of this very row in one descriptor swap. No field is
     * written one by one, so a refusal raised while the draft was patched leaves this row whole,
     * and the handle - with every view wrapping it - stays the same object.
     *
     * @param RtState $draft Draft this row's {@see self::draftRow()} made, patched and validated since
     * @throws FrameworkLogicException When the draft is not one of this row's own
     */
    final public function publishDraft(RtState $draft): void
    {
        if ($draft::class !== static::class
            || $draft->rowBinding === null
            || $this->rowBinding === null
            || $draft->rowBinding->incarnation !== $this->rowBinding->incarnation) {
            throw new FrameworkLogicException('Only a draft of this very row can be published into it');
        }
        $this->rowBinding->replace($draft->rowBinding->current()->retain(RtRowRole::Resident));
    }

    /**
     * A diff of one list field holding this row's list with one more element at its end, for the
     * write door that patches the row in portions ({@see RtActions::applyDiffWithSync()}).
     *
     * Built over the row's own pages: the elements already held are referenced, never
     * materialized or copied, so appending to a long list costs a bounded walk of its entries
     * rather than a native copy of the whole list and a spread.
     *
     * @param string $key Declared list field of this row
     * @param string $element Bounded element to append
     * @return RtRowValues Segmented diff carrying that field alone
     * @throws RtRowCodecNotSupportedException When the row has no binding migration
     * @throws InvalidFormatException When the field holds no segmented list, or a stored page is malformed
     * @throws FrameworkLogicException When a backing lease closed - a broken Runtime invariant
     */
    final public function appendedListDiff(string $key, string $element): RtRowValues
    {
        if ($this->rowFieldDefaults() === []) {
            throw new RtRowCodecNotSupportedException('Runtime row has no descriptor binding: ' . static::class);
        }
        $held = $this->rowValues()[$key] ?? null;
        if ($held !== null && !$held instanceof RtRowValues) {
            throw new InvalidFormatException('Runtime row field holds no segmented list: ' . $key);
        }
        try {
            return RtRowValues::completed(self::appendedList($key, $held, $element, RtRowArena::process()));
        } catch (LogicException $broken) {
            throw new FrameworkLogicException($broken->getMessage(), previous: $broken);
        }
    }

    /**
     * @internal Stages, for this row that keeps its identity, the values a snapshot generation
     * restored and validated for its id, as both its values and its baseline. Nothing becomes
     * visible here: the pair waits in the generation until it is installed on the collection's
     * root ({@see RtRowRoot::install()}), and is read through this row's handle from then on.
     * Constant work per field; nothing is exported, compared or copied.
     *
     * @param RtState $restored Row of this very class the generation restored for this one's id
     * @param RtRowRoot $root Resolution root of the collection this row stands in
     * @param WeakMap<RtRowBinding, array{RtRowDescriptor, RtRowDescriptor}> $generation Generation being prepared
     * @throws FrameworkLogicException When the restored row is not of this row's class or either row has no binding
     * @throws InvalidFormatException When retained upload identities are malformed
     * @throws LogicException When a backing lease closed
     */
    final public function stageSnapshotValues(RtState $restored, RtRowRoot $root, WeakMap $generation): void
    {
        if ($restored::class !== static::class || $this->rowFieldDefaults() === []) {
            throw new FrameworkLogicException('Only a migrated row of the same class can stage snapshot values');
        }
        $this->rowValues();
        $restored->rowValues();
        $this->rowBinding->attach($root);
        $generation[$this->rowBinding] = [
            $restored->rowBinding->current()->retain(RtRowRole::Candidate),
            $restored->rowBinding->current()->retain(RtRowRole::Candidate),
        ];
    }

    /**
     * @internal Makes the values of a row restored for a snapshot its baseline as well, the way a
     * hand-over marks what it wrote; constant work, nothing exported. The row stands in no
     * collection yet, so no selector hears of it here.
     *
     * @throws FrameworkLogicException When the row has no binding
     * @throws InvalidFormatException When retained upload identities are malformed
     * @throws LogicException When a backing lease closed
     */
    final public function adoptSnapshotBaseline(): void
    {
        if ($this->rowFieldDefaults() === []) {
            throw new FrameworkLogicException('Only a migrated row can adopt a snapshot baseline');
        }
        $this->rowValues();
        $this->rtSyncBaseline = $this->rowBinding->current()->retain(RtRowRole::Ordinary);
    }

    /**
     * Resets the baseline; migrated rows retain pages rather than a native value graph. A row
     * standing in a collection commits these values into its snapshot selector as well.
     *
     * @throws InvalidFormatException When the row's values cannot be held by the snapshot selector
     * @throws FrameworkLogicException When the snapshot selector's pages or preparation lineage broke
     * @throws InvalidArgumentException When a retirement allowance of the selector is invalid
     */
    public function markRtSyncBaseline(): void
    {
        if ($this->rowFieldDefaults() !== []) {
            $this->rowValues();
            $this->rtSyncBaseline = $this->rowBinding->current()->retain(RtRowRole::Ordinary);
            RtStates::noteCommittedRow($this);
        } else {
            $this->rtSyncBaseline = $this->toArray();
            RtStates::noteCommittedRow($this, $this->rtSyncBaseline);
        }
    }

    /**
     * Queue RT_SYNC_UPDATED for all fields that differ from the last baseline, then advance the baseline.
     * Does not persist to DB; cross-worker runtime sync only.
     *
     * A row with a binding computes the changed fields and their previous values on its segmented
     * pages ({@see RtFrozenRow::changes()}) and hands them to the signal as they are: neither the row
     * nor the baseline is materialized. A legacy-only row keeps its native comparison.
     *
     * This is the late half of a write whose fields are already assigned: it never refuses for want
     * of ordinary capacity - that refusal belongs to the door before the first assignment.
     *
     * Under an open transaction the frame leaves at the commit, and the write leaves a step for the
     * rollback: the changed fields get their baseline values back and the baseline goes back too,
     * so the next sync() sends the same diff again. The step goes through {@see applyDiff()}, the
     * door a foreign edit comes in by - a state without it is not rolled back, exactly as it takes
     * no foreign edit. Written whatever the collection key: memory is memory.
     *
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws InvalidFormatException When stored pages or retained upload identities are malformed
     * @throws LogicException When an internal backing lease closed
     * @throws FrameworkLogicException When the snapshot selector's pages or preparation lineage broke
     */
    public function sync(): void
    {
        if ($this->rowFieldDefaults() === []) {
            $this->syncLegacy();

            return;
        }
        $changes = RtRowValues::completed($this->frozenValues()->changes(RtRowArena::process()));
        if ($changes->diff->isEmptyMap()) {
            return;
        }
        $this->queueSyncUpdated($changes->diff, $changes->previous);

        $baseline = $this->rtSyncBaseline;
        $previous = $changes->previous;
        Database::onRollback(function () use ($previous, $baseline): void {
            $this->applyDiff(RtRowValues::nativeMap($previous));
            $this->rtSyncBaseline = $baseline;
        });
        $this->rtSyncBaseline = $this->rowBinding->current()->retain(RtRowRole::Ordinary);
        RtStates::noteCommittedRow($this);
    }

    /**
     * Set keys a write of this row touches: the one it is stored under and the one it is edited to,
     * each once.
     *
     * Stored is the {@see self::SET_VIA} field in the last synced copy of the row, edited is the
     * same field in `$diff` when the diff carries it and in the row as it stands otherwise. A row
     * never synced has no stored copy of the field and writes into its edited set alone - a new
     * row is born in one set. A write that moves the row to another set names two keys, so only
     * the owner of the whole collection may make it.
     *
     * Empty when the class names no field, and when either value is null or not a scalar: such a
     * row is in nobody's set, and the write guard reads the empty list as belonging to no claim
     * over a set. The twin of {@see Object_::touchedSetKeys()} without its climb, since a runtime
     * row has no set tree; it lives on the row because the synced copy is private to it.
     *
     * @param array<string, mixed> $diff Fields a pending write is about to apply, empty when the row already holds them
     * @return list<string> Set keys the write touches, empty for a row outside every set
     */
    public function touchedSetKeys(array $diff = []): array
    {
        $field = static::SET_VIA;
        if ($field === '') {
            return [];
        }

        $edited = array_key_exists($field, $diff) ? $diff[$field] : ($this->toArray()[$field] ?? null);
        $baseline = $this->synchronousBaseline();
        $values = array_key_exists($field, $baseline)
            ? [$baseline[$field], $edited]
            : [$edited];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                return [];
            }
        }

        return array_values(array_unique(array_map(strval(...), $values)));
    }

    /**
     * Get unique identifier for this state
     *
     * Must be implemented by child classes to provide a unique key
     * for storing this state in the collection.
     *
     * @return string Unique identifier
     */
    abstract public function getId(): string;

    /**
     * Create state instance from row (array) for RT sync deserialization.
     *
     * @param array<string, mixed> $row Full state data (keys match toArray() output)
     * @return static State instance
     * @throws HilosException Whatever the concrete state's read of the row raises
     */
    abstract public static function fromRow(array $row): static;

    /**
     * Apply diff to state fields for RT sync update.
     *
     * Override in child classes to support updates. Default no-op. It is also the door a rollback
     * puts a state's own edit back by, so a state that does not override it is not rolled back.
     *
     * @param array<string, mixed> $diff Changed fields => values
     * @throws HilosException Whatever the concrete state's read of the diff raises
     */
    public function applyDiff(array $diff): void
    {
    }

    /**
     * Convert state to array representation.
     *
     * @return array<string, mixed> State fields as associative array
     */
    abstract public function toArray(): array;

    /**
     * Codec owners call this after their binding migration, without a legacy fallback.
     *
     * @return RtFrozenRow Independently retained immutable descriptors
     * @throws InvalidFormatException When retained upload identities are malformed
     * @throws LogicException When an internal backing lease closed
     */
    final protected function frozenValues(): RtFrozenRow
    {
        $this->rowValues();

        return new RtFrozenRow(
            $this->rowBinding->current()->retain(RtRowRole::Snapshot),
            ($this->rtSyncBaseline instanceof RtRowDescriptor ? $this->rtSyncBaseline : new RtRowDescriptor([], []))
                ->retain(RtRowRole::Snapshot),
            $this->rowBinding->incarnation,
        );
    }

    /** @return array<string, mixed> Fixed export defaults; empty declares a legacy-only row */
    protected function rowFieldDefaults(): array
    {
        return [];
    }

    /** @return array<string, mixed> Fixed field backing; variable values remain page leases */
    final protected function rowValues(): array
    {
        $this->rowBinding ??= RtRowBinding::create(new RtRowDescriptor([], $this->rowFieldDefaults()));

        return $this->rowBinding->current()->fields;
    }

    /**
     * The array is a fixed codec schema, never a native variable graph.
     *
     * @param array<string, mixed> $values Prepared fixed descriptor
     */
    final protected function replaceRowValues(array $values): void
    {
        $this->rowValues();
        $this->rowBinding->replace(new RtRowDescriptor($values, $this->rowFieldDefaults()));
    }

    /**
     * @param string $key Declared field name
     * @param mixed $value Codec-owned scalar, immutable collection or segmented value
     */
    final protected function setRowValue(string $key, mixed $value): void
    {
        $values = $this->rowValues();
        $values[$key] = $value;
        $this->replaceRowValues($values);
    }

    /**
     * Reads a string field of a runtime row that the state cannot be built without.
     *
     * A runtime row is written by {@see toArray()} on another node, so a key that is absent
     * or holds another type is a row that lost the field on the way, not a row that never
     * had it. A cast would hand every reader below a value that never arrived, and the row
     * would look like a state somebody chose rather than like a frame that broke.
     *
     * Refusal is the whole contract of this family, and the state has two more beside it:
     * a field that is legitimately empty is nullable and read by {@see self::optionalString()}
     * and its siblings, and a field a partial diff simply did not carry is read by
     * {@see self::patchString()} and its siblings, which leave it as it was.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return string Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-string
     */
    final protected static function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidFormatException('Runtime row carries no string under key ' . $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return int Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-integer
     */
    final protected static function requireInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidFormatException('Runtime row carries no integer under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads a floating-point field of a runtime row that the state cannot be built without.
     *
     * An integer is widened rather than refused: the row crosses the nodes as JSON, where
     * `json_encode(0.0)` writes `0`, so a whole number comes back an integer and a strict
     * `is_float()` would refuse a row our own {@see toArray()} wrote. A string of digits
     * stays refused - that is a sender writing a number as text, not the format losing the
     * fraction.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return float Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds neither a float nor an integer
     */
    final protected static function requireFloat(array $source, string $key): float
    {
        $value = $source[$key] ?? null;
        if (!is_float($value) && !is_int($value)) {
            throw new InvalidFormatException('Runtime row carries no number under key ' . $key);
        }

        return (float)$value;
    }

    /**
     * Reads a boolean field of a runtime row that the state cannot be built without.
     *
     * `false` is a flag the sending node lowered, not the absence of one, so it passes
     * through untouched. That is why a boolean needs a reader of its own: `?? false` reads
     * a lost field and a deliberately lowered flag as the same thing.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return bool Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-boolean
     */
    final protected static function requireBool(array $source, string $key): bool
    {
        $value = $source[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidFormatException('Runtime row carries no boolean under key ' . $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return array<string, mixed> Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-array
     */
    final protected static function requireArray(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidFormatException('Runtime row carries no array under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads a list of strings the state cannot be built without.
     *
     * An empty list is a value: a node with nothing to list writes `[]`, and the field is
     * present and read. What is refused is an array that is not a list at all, or one whose
     * elements are not strings - both mean the row no longer holds what this state reads.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return list<string> Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds anything but a list of strings
     */
    final protected static function requireStringList(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidFormatException('Runtime row carries no list of strings under key ' . $key);
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidFormatException('Runtime row carries no list of strings under key ' . $key);
            }
        }

        return $value;
    }

    /**
     * Reads a string field of a runtime row that is allowed to be absent.
     *
     * Absence answers `null` - that is what the field being optional means, and a node
     * running an older version simply does not write a field it does not know yet, so a
     * strict read would refuse its whole row. A key that is present and holds the wrong
     * type is not absence: the sender filled the field with something this state cannot
     * read, and the row is refused exactly as a lost required field is.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return ?string Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-string
     */
    final protected static function optionalString(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidFormatException('Runtime row carries a non-string under key ' . $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return ?int Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-integer
     */
    final protected static function optionalInt(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidFormatException('Runtime row carries a non-integer under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads a floating-point field of a runtime row that is allowed to be absent.
     *
     * An integer is widened for the same reason it is in {@see self::requireFloat()}: JSON
     * does not keep a whole float a float.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return ?float Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds neither a float nor an integer
     */
    final protected static function optionalFloat(array $source, string $key): ?float
    {
        $value = $source[$key] ?? null;
        if ($value !== null && !is_float($value) && !is_int($value)) {
            throw new InvalidFormatException('Runtime row carries a non-number under key ' . $key);
        }

        return $value !== null ? (float)$value : null;
    }

    /**
     * Reads a boolean field of a runtime row that is allowed to be absent.
     *
     * Absence answers `null`, which is what distinguishes it from a flag the sender lowered
     * on purpose; `false` arrives as `false`.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return ?bool Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds a non-boolean
     */
    final protected static function optionalBool(array $source, string $key): ?bool
    {
        $value = $source[$key] ?? null;
        if ($value !== null && !is_bool($value)) {
            throw new InvalidFormatException('Runtime row carries a non-boolean under key ' . $key);
        }

        return $value;
    }

    /**
     * Reads a list of strings that is allowed to be absent.
     *
     * An empty list is a value: a node that has nothing to list writes `[]`. Absence answers
     * `null`, and an array that is not a list of strings is refused the same way
     * {@see self::requireStringList()} refuses one.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return ?list<string> Value stored under the key, or null when the key is absent
     * @throws InvalidFormatException When the key is present and holds anything but a list of strings
     */
    final protected static function optionalStringList(array $source, string $key): ?array
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return self::requireStringList($source, $key);
    }

    /**
     * Reads a string field out of a diff, or keeps the value the state already holds.
     *
     * A diff carries the fields that changed, so a key it does not carry means the field did
     * not change - not that it was emptied. The two are told apart here and nowhere else:
     * an `array_key_exists()` written by hand at every field holds only as long as its
     * author remembers it, and the one it forgets looks like an ordinary assignment while
     * it silently clears the field. No test tells that apart from a diff that really did
     * carry a zero.
     *
     * The value comes back rather than being written through a reference, so the field being
     * patched is visible in the calling line: `$this->x = self::patchString($diff, self::x, $this->x);`
     *
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param string $current Value the state holds now
     * @return string Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds a non-string
     */
    final protected static function patchString(array $diff, string $key, string $current): string
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::requireString($diff, $key);
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param int $current Value the state holds now
     * @return int Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds a non-integer
     */
    final protected static function patchInt(array $diff, string $key, int $current): int
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::requireInt($diff, $key);
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param float $current Value the state holds now
     * @return float Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds neither a float nor an integer
     */
    final protected static function patchFloat(array $diff, string $key, float $current): float
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::requireFloat($diff, $key);
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param bool $current Value the state holds now
     * @return bool Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds a non-boolean
     */
    final protected static function patchBool(array $diff, string $key, bool $current): bool
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::requireBool($diff, $key);
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param array<string, mixed> $current Value the state holds now
     * @return array<string, mixed> Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds a non-array
     */
    final protected static function patchArray(array $diff, string $key, array $current): array
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::requireArray($diff, $key);
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param list<string> $current Value the state holds now
     * @return list<string> Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds anything but a list of strings
     */
    final protected static function patchStringList(array $diff, string $key, array $current): array
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::requireStringList($diff, $key);
    }

    /**
     * Reads an optional string out of a diff, or keeps the value the state already holds.
     *
     * This is where the two kinds of emptiness stop looking alike: a diff without the key
     * leaves the field as it was, while a diff carrying the key with `null` clears it on
     * purpose. Reading the field with {@see self::optionalString()} alone would answer null
     * to both and turn every unrelated diff into an erasure.
     *
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param ?string $current Value the state holds now
     * @return ?string Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds a non-string
     */
    final protected static function patchOptionalString(array $diff, string $key, ?string $current): ?string
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::optionalString($diff, $key);
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param ?int $current Value the state holds now
     * @return ?int Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds a non-integer
     */
    final protected static function patchOptionalInt(array $diff, string $key, ?int $current): ?int
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::optionalInt($diff, $key);
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param ?float $current Value the state holds now
     * @return ?float Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds neither a float nor an integer
     */
    final protected static function patchOptionalFloat(array $diff, string $key, ?float $current): ?float
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::optionalFloat($diff, $key);
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param ?bool $current Value the state holds now
     * @return ?bool Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds a non-boolean
     */
    final protected static function patchOptionalBool(array $diff, string $key, ?bool $current): ?bool
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::optionalBool($diff, $key);
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @param string $key Row key holding the field
     * @param ?list<string> $current Value the state holds now
     * @return ?list<string> Value from the diff, or the current one when the diff does not carry the key
     * @throws InvalidFormatException When the diff carries the key and it holds anything but a list of strings or null
     */
    final protected static function patchOptionalStringList(array $diff, string $key, ?array $current): ?array
    {
        if (!array_key_exists($key, $diff)) {
            return $current;
        }

        return self::optionalStringList($diff, $key);
    }

    /**
     * The native comparison of a legacy-only row, kept as it was before rows had a binding.
     *
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    private function syncLegacy(): void
    {
        $current = $this->toArray();
        $nativeBaseline = $this->synchronousBaseline();
        $diff = [];
        foreach ($current as $k => $v) {
            if (($nativeBaseline[$k] ?? null) !== $v) {
                $diff[$k] = $v;
            }
        }
        if ($diff === []) {
            return;
        }
        $previous = array_intersect_key($nativeBaseline, $diff);
        $this->queueSyncUpdated($diff, $previous);

        $baseline = $this->rtSyncBaseline;
        Database::onRollback(function () use ($previous, $baseline): void {
            $this->applyDiff($previous);
            $this->rtSyncBaseline = $baseline;
        });
        $this->rtSyncBaseline = $current;
        RtStates::noteCommittedRow($this, $current);
    }

    /**
     * Judges the write by the set the row stands in and queues the frame, when the row has a
     * collection key and a router to send it by.
     *
     * @param array<string, mixed>|RtRowValues $diff Changed fields
     * @param array<string, mixed>|RtRowValues $previous Baseline values of the changed fields
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    private function queueSyncUpdated(array|RtRowValues $diff, array|RtRowValues $previous): void
    {
        $collectionKey = static::getRtCollectionKey();
        if ($collectionKey === '' || Hilos::$sr === null) {
            return;
        }
        RtTruthSourceRegistry::checkCanWriteState(
            $collectionKey,
            $this->getId(),
            $this->touchedSetKeys(),
            TruthSourceOperation::Update,
        );
        Hilos::$sr->queueRtSyncSignal(
            SignalConstants::RT_SYNC_UPDATED,
            new RtSyncUpdatedSignalData(
                $collectionKey,
                $this->getId(),
                $diff,
                ExecutionContext::currentAcceptKey(),
                ExecutionContext::currentRequestId(),
                previous: $previous,
            ),
        );
    }

    /**
     * @param string $key List field the diff carries
     * @param ?RtRowValues $held List the row holds, or null while the field keeps its empty default
     * @param string $element Element to append
     * @param RtRowArena $arena Storage owner of the row's pages
     * @return Generator<int, RtWork, int, RtRowValues> One-field diff over the held pages
     * @throws InvalidFormatException When the held value is not a list
     * @throws LogicException When a writer lifetime is violated or a page lease closed
     */
    private static function appendedList(string $key, ?RtRowValues $held, string $element, RtRowArena $arena): Generator
    {
        $list = new RtRowWriter($arena);
        $position = 0;
        if ($held !== null) {
            if ($held->reader()->kind() !== RtRowKind::Map) {
                throw new InvalidFormatException('Runtime row field holds no list: ' . $key);
            }
            $cursor = $held->reader()->entries();
            while (($entry = yield from $cursor->advance()) !== null) {
                yield from $list->writeEntry($entry->key, $entry->value);
                ++$position;
            }
        }
        yield from $list->write($position, yield from RtRowWriter::encode($element, $arena));
        $diff = new RtRowWriter($arena);
        yield from $diff->write($key, yield from $list->finish());

        return yield from $diff->finish();
    }

    /** @return array<string, mixed> Explicit synchronous compatibility materialization */
    private function synchronousBaseline(): array
    {
        if (is_array($this->rtSyncBaseline)) {
            return $this->rtSyncBaseline;
        }
        // Explicit native compatibility boundary, using the same typed reads as before.
        // Frozen preparation never creates this application-facing view.
        $view = clone $this;
        $view->rowBinding->replace($this->rtSyncBaseline);

        return $view->toArray();
    }
}

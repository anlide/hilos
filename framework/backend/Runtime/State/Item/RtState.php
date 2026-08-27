<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\State\RtStatePropertyNotFoundException;
use Hilos\Runtime\Exception\State\RtStateReadOnlyException;
use Hilos\Runtime\Exception\State\RtStateUnserializeException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\TruthSource\RtTruthSourceRegistry;

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
     * Copy of the row as of the last "synced" moment: {@see sync()} diffs {@see toArray()} against this
     * to know which fields to put in RT_SYNC_UPDATED (same idea as Object_ entity vs entitySync, without DB).
     * Written only by {@see markRtSyncBaseline()} and at the end of {@see sync()}.
     *
     * @var array<string, mixed>
     */
    private array $rtSyncBaseline = [];

    /**
     * Protected constructor. Child classes must use static factory methods (e.g. fromRow).
     */
    protected function __construct()
    {
    }

    /**
     * Protected clone. Prevents cloning of RtState instances.
     */
    protected function __clone()
    {
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
     * Resets diff baseline to the current row (call after create/fromRow and after inbound RT applyDiff).
     */
    public function markRtSyncBaseline(): void
    {
        $this->rtSyncBaseline = $this->toArray();
    }

    /**
     * Queue RT_SYNC_UPDATED for all fields that differ from the last baseline, then advance the baseline.
     * Does not persist to DB; cross-worker runtime sync only.
     *
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function sync(): void
    {
        $current = $this->toArray();
        $diff = [];
        foreach ($current as $k => $v) {
            if (($this->rtSyncBaseline[$k] ?? null) !== $v) {
                $diff[$k] = $v;
            }
        }
        if ($diff === []) {
            return;
        }

        $collectionKey = static::getRtCollectionKey();
        if ($collectionKey !== '' && Hilos::$sr !== null) {
            RtTruthSourceRegistry::checkCanWriteState($collectionKey, $this->getId(), TruthSourceOperation::Update);
            Hilos::$sr->queueRtSyncSignal(
                SignalConstants::RT_SYNC_UPDATED,
                new RtSyncUpdatedSignalData($collectionKey, $this->getId(), $diff, ExecutionContext::currentAcceptKey()),
            );
        }

        $this->rtSyncBaseline = $current;
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
     * Override in child classes to support updates. Default no-op.
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
}

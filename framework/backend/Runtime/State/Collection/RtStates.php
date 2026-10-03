<?php

namespace Hilos\Runtime\State\Collection;

use ArrayAccess;
use Countable;
use Generator;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Database\Database;
use Hilos\Runtime\Exception\State\RtStatesCloneException;
use Hilos\Runtime\Exception\State\RtStatesUnserializeException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Context\RtContext;
use IteratorAggregate;
use OutOfBoundsException;

/**
 * Base class for runtime state collections.
 *
 * RtStates is the single source of truth for runtime state data (analogous to Objects for database).
 * It stores RtState instances and provides collection operations.
 * RtCollection instances are lightweight wrappers that reference RtStates.
 *
 * Unlike database Objects, RtStates does not load from or sync to persistent storage.
 * All data lives only in memory for the lifetime of the process.
 *
 * @template T of RtState
 * @implements ArrayAccess<string, T>
 * @implements IteratorAggregate<string, T>
 */
abstract class RtStates implements IteratorAggregate, ArrayAccess, Countable
{
    /** @var class-string<RtState> */
    public const string STATE_CLASS = '';

    /** @var array<string, T> state ID => RtState map */
    protected array $states = [];

    /** @var ?string Name this collection is mounted under, or null while it is not mounted */
    private ?string $collectionName = null;

    /**
     * Protected constructor - use static factory methods.
     */
    protected function __construct()
    {
    }

    /**
     * Initialize empty collection.
     *
     * @return static New RtStates instance
     */
    public static function init(): static
    {
        return new static();
    }

    /**
     * Public clone - prevent cloning.
     *
     * @throws RtStatesCloneException Always, cloning not allowed
     */
    public function __clone(): void
    {
        throw new RtStatesCloneException('RtStates cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization.
     *
     * @throws RtStatesUnserializeException Always, unserialization not allowed
     */
    public function __wakeup(): void
    {
        throw new RtStatesUnserializeException('RtStates cannot be unserialized');
    }

    /**
     * Debug info.
     *
     * @return array<string, array<string, mixed>> Id => state array map
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Tells this collection the name it is mounted under.
     *
     * Called by {@see RtContext::bindStateCollectionNames()} once the registry is complete. The
     * database side has carried its own collection key since always; on this side only the view
     * knew the name, and a store that cannot name itself cannot announce its own changes.
     *
     * @param string $name Name this collection is mounted under
     */
    public function setCollectionName(string $name): void
    {
        $this->collectionName = $name;
    }

    /**
     * Returns the name this collection is mounted under.
     *
     * @return ?string Mounted name, or null while this collection is not mounted
     */
    public function getCollectionName(): ?string
    {
        return $this->collectionName;
    }

    /**
     * Add state to collection and announce the new membership.
     *
     * A state already standing under that id is replaced, and the announcement says created for
     * that too: what every dependent view has to hear is that the id now holds a different row.
     * Under an open transaction what the id held is remembered first, for a rollback to put back.
     *
     * @param T $state State instance to add
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function add(RtState $state): void
    {
        $this->rememberId($state->getId());
        $this->states[$state->getId()] = $state;
        if ($this->collectionName === null) {
            return;
        }
        SourceChangeBus::publish(SourceChange::rtCreated(
            $this->collectionName,
            $state->getId(),
            $state->toArray(),
            ExecutionContext::currentAcceptKey(),
            ExecutionContext::currentRequestId(),
        ));
    }

    /**
     * Remove state from collection by ID and announce the lost membership.
     *
     * The row is read before the removal, so the announcement carries what the id held rather
     * than nothing; an id holding nothing is still announced, because the callers this replaces
     * broadcast that removal too and a receiver that has the row must lose it. Under an open
     * transaction what the id held is remembered first, for a rollback to put back.
     *
     * @param string $id State ID
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function remove(string $id): void
    {
        $previous = $this->states[$id] ?? null;
        $this->rememberId($id);
        unset($this->states[$id]);
        if ($this->collectionName === null) {
            return;
        }
        SourceChangeBus::publish(SourceChange::rtDeleted(
            $this->collectionName,
            $id,
            $previous?->toArray() ?? [],
            ExecutionContext::currentAcceptKey(),
            ExecutionContext::currentRequestId(),
        ));
    }

    /**
     * Check if state exists in collection.
     *
     * @param ?string $id State ID, or null for a missing optional runtime key
     * @return bool True if state exists
     */
    public function has(?string $id): bool
    {
        if ($id === null) {
            return false;
        }
        return isset($this->states[$id]);
    }

    /**
     * Get state by ID.
     *
     * @param ?string $id State ID, or null for a missing optional runtime key
     * @return ?T State instance or null if not found
     */
    public function get(?string $id): ?RtState
    {
        if ($id === null) {
            return null;
        }
        return $this->states[$id] ?? null;
    }

    /**
     * Clear all states from collection.
     *
     * Announces nothing, unlike the point mutations. A whole-collection wipe already travels its
     * own road end to end - the caller broadcasts a delete per row and empties the view cache in
     * one go - and routing it through per-row announcements would change what goes over the wire.
     * Under an open transaction the whole map is remembered first, for a rollback to put back.
     */
    public function clear(): void
    {
        $this->rememberAll();
        $this->states = [];
    }

    /**
     * Convert all states to array.
     *
     * @param bool $idAsIndex Use ID as array index
     * @return array<string|int, array<string, mixed>> Id => state array (if idAsIndex) or list of state arrays
     */
    public function toArray(bool $idAsIndex = true): array
    {
        $result = [];
        foreach ($this->states as $id => $state) {
            if ($idAsIndex) {
                $result[$id] = $state->toArray();
            } else {
                $result[] = $state->toArray();
            }
        }
        return $result;
    }

    /**
     * Get first state.
     *
     * @return ?T First state or null if empty
     */
    public function first(): ?RtState
    {
        $keys = array_keys($this->states);
        $firstKey = $keys[0] ?? null;
        return $firstKey !== null ? $this->states[$firstKey] : null;
    }

    /**
     * Get last state.
     *
     * @return ?T Last state or null if empty
     */
    public function last(): ?RtState
    {
        $keys = array_keys($this->states);
        $lastKey = end($keys);
        return $lastKey !== false ? $this->states[$lastKey] : null;
    }

    // ==================== ArrayAccess ====================

    /**
     * Check if state exists at offset.
     *
     * @param mixed $offset State ID, or null for a missing optional runtime key
     * @return bool True if state exists
     */
    public function offsetExists(mixed $offset): bool
    {
        if ($offset === null) {
            return false;
        }
        return isset($this->states[$offset]);
    }

    /**
     * Get state at offset.
     *
     * @param mixed $offset State ID, or null for a missing optional runtime key
     * @return ?T State instance or null if not found
     * @throws OutOfBoundsException When the concrete collection requires the key and no state is stored under it
     */
    public function offsetGet(mixed $offset): ?RtState
    {
        if ($offset === null) {
            return null;
        }
        return $this->states[$offset] ?? null;
    }

    /**
     * Set state at offset.
     *
     * Routed through {@see self::add()} so that membership has exactly one announcement point,
     * which also means the state is stored under its own id and the offset is not consulted.
     * Storing a row under a key it does not answer to was never usable anyway: every read path
     * here addresses a state by {@see RtState::getId()}.
     *
     * @param mixed $offset Ignored, the state is stored under its own ID
     * @param T $value RtState instance to set
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof RtState) {
            $this->add($value);
        }
    }

    /**
     * Remove state at offset.
     *
     * Routed through {@see self::remove()} for the same single announcement point.
     *
     * @param mixed $offset State ID to remove, or null for no-op
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function offsetUnset(mixed $offset): void
    {
        if ($offset === null) {
            return;
        }
        $this->remove((string)$offset);
    }

    // ==================== Countable ====================

    /**
     * Get number of states in collection.
     *
     * @return int Number of states
     */
    public function count(): int
    {
        return count($this->states);
    }

    // ==================== IteratorAggregate ====================

    /**
     * List the state IDs currently stored, in insertion order.
     *
     * This is the snapshot a walk is taken over, and it is public because the view layer walks
     * by the keys of its truth source rather than by keys of its own wrapper cache.
     *
     * Cast on the way out, because a state id that reads as a number is an integer once it is
     * an array key, and the view layer this feeds addresses its wrappers by string.
     *
     * @return list<string> State IDs
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->states));
    }

    /**
     * Walk the states over a snapshot of the keys taken when the walk starts.
     *
     * Each walk gets its own generator, so a nested foreach over the same collection does not
     * disturb the outer one. A key removed after the snapshot was taken is skipped rather than
     * answered as null, and a state added during the walk is not seen - the snapshot is already
     * taken.
     *
     * @return Generator<string, T> State ID => RtState
     */
    public function getIterator(): Generator
    {
        foreach ($this->keys() as $key) {
            $state = $this->states[$key] ?? null;
            if ($state === null) {
                continue;
            }
            yield $key => $state;
        }
    }

    /**
     * Remembers what one id holds, for a rollback to put it back: the state, or nothing.
     *
     * Outside a transaction nothing is remembered ({@see Database::onRollback()}). The step puts
     * the very instance back - so View wrappers holding it keep their state - or drops the id, and
     * tells the mirrors when the instance under the id changed by it.
     *
     * @param string $id State ID about to change
     */
    private function rememberId(string $id): void
    {
        $held = $this->states[$id] ?? null;
        Database::onRollback(function () use ($id, $held): void {
            $replaced = $this->states[$id] ?? null;
            if ($held === null) {
                unset($this->states[$id]);
            } else {
                $this->states[$id] = $held;
            }
            $this->announceRestored($id, $held, $replaced);
        });
    }

    /**
     * Remembers the whole map, for a rollback to put it back after a wipe.
     *
     * The map is copied lazily: its first change after this pays for one copy. The step tells the
     * mirrors about every id whose instance it changed.
     */
    private function rememberAll(): void
    {
        $map = $this->states;
        Database::onRollback(function () use ($map): void {
            $replaced = $this->states;
            $this->states = $map;
            foreach (array_keys($map + $replaced) as $id) {
                $this->announceRestored((string)$id, $map[$id] ?? null, $replaced[$id] ?? null);
            }
        });
    }

    /**
     * Tells the mirrors a rollback changed what one id holds: the state put back, or the one taken away.
     *
     * The mirrors alone, the way they heard the write itself: a reaction and the other processes
     * never heard what the rollback takes back. A collection that is not mounted has no name to
     * tell it under, and tells nothing, as its writes do.
     *
     * @param string $id State ID the rollback changed
     * @param ?T $restored State the id holds again, null when the rollback dropped the id
     * @param ?T $replaced State the id held until the rollback, null when it held none
     * @throws SourceChangeSubscriberException Whatever a mirror raises
     */
    private function announceRestored(string $id, ?RtState $restored, ?RtState $replaced): void
    {
        if ($this->collectionName === null || $restored === $replaced) {
            return;
        }

        if ($restored !== null) {
            SourceChangeBus::publishToMirrors(SourceChange::rtCreated(
                $this->collectionName,
                $id,
                $restored->toArray(),
                ExecutionContext::currentAcceptKey(),
                ExecutionContext::currentRequestId(),
            ));
        } elseif ($replaced !== null) {
            SourceChangeBus::publishToMirrors(SourceChange::rtDeleted(
                $this->collectionName,
                $id,
                $replaced->toArray(),
                ExecutionContext::currentAcceptKey(),
                ExecutionContext::currentRequestId(),
            ));
        }
    }
}

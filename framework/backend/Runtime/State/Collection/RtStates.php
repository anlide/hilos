<?php

namespace Hilos\Runtime\State\Collection;

use ArrayAccess;
use Countable;
use Hilos\Runtime\Exception\State\RtStatesCloneException;
use Hilos\Runtime\Exception\State\RtStatesUnserializeException;
use Hilos\Runtime\State\Item\RtState;
use Iterator;

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
 * @implements Iterator<string, T>
 */
abstract class RtStates implements Iterator, ArrayAccess, Countable
{
    /** @var class-string<RtState> */
    public const string STATE_CLASS = '';

    /** @var array<string, T> state ID => RtState map */
    protected array $states = [];

    /** @var int current iterator position */
    protected int $index = 0;

    /** @var int backup iterator position for nested iteration */
    private int $backupIndex = 0;

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
     * Add state to collection.
     *
     * @param T $state State instance to add
     */
    public function add(RtState $state): void
    {
        $this->states[$state->getId()] = $state;
    }

    /**
     * Remove state from collection by ID.
     *
     * @param string $id State ID
     */
    public function remove(string $id): void
    {
        unset($this->states[$id]);
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
     */
    public function clear(): void
    {
        $this->states = [];
        $this->index = 0;
        $this->backupIndex = 0;
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

    /**
     * Backup current iterator position.
     */
    public function backupIndex(): void
    {
        $this->backupIndex = $this->index;
    }

    /**
     * Restore iterator position from backup.
     */
    public function restoreIndex(): void
    {
        $this->index = $this->backupIndex;
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
     * @param mixed $offset State ID or null (uses value ID when null)
     * @param T $value RtState instance to set
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof RtState) {
            $this->states[$offset ?? $value->getId()] = $value;
        }
    }

    /**
     * Remove state at offset.
     *
     * @param mixed $offset State ID to remove, or null for no-op
     */
    public function offsetUnset(mixed $offset): void
    {
        if ($offset === null) {
            return;
        }
        unset($this->states[$offset]);
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

    // ==================== Iterator ====================

    /**
     * Get current state in iteration.
     *
     * @return ?T Current state or null if position invalid
     */
    public function current(): ?RtState
    {
        $keys = array_keys($this->states);
        if (!isset($keys[$this->index])) {
            return null;
        }
        return $this->states[$keys[$this->index]];
    }

    /**
     * Get current iterator key.
     *
     * @return ?string Current state ID or null if position invalid
     */
    public function key(): ?string
    {
        $keys = array_keys($this->states);
        return $keys[$this->index] ?? null;
    }

    /**
     * Advance iterator to next element.
     */
    public function next(): void
    {
        ++$this->index;
    }

    /**
     * Reset iterator to first element.
     */
    public function rewind(): void
    {
        $this->index = 0;
    }

    /**
     * Check if current iterator position is valid.
     *
     * @return bool True if position has element
     */
    public function valid(): bool
    {
        $keys = array_keys($this->states);
        return $this->index < count($keys);
    }
}

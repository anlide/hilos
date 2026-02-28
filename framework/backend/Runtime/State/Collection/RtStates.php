<?php

namespace Hilos\Runtime\State\Collection;

use ArrayAccess;
use Countable;
use Hilos\Runtime\Exception\State\RtStatesCloneException;
use Hilos\Runtime\Exception\State\RtStatesUnserializeException;
use Hilos\Runtime\State\Item\RtState;
use Iterator;

/**
 * Base class for runtime state collections
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

    /**
     * State objects storage
     *
     * @var RtState
     */
    protected array $states = [];

    /**
     * Current iterator position
     *
     * @var int
     */
    protected int $index = 0;

    /**
     * Backup iterator position
     *
     * @var int
     */
    private int $backupIndex = 0;

    /**
     * Protected constructor - use static factory methods
     */
    protected function __construct()
    {
    }

    /**
     * Initialize empty collection
     *
     * @return static
     */
    public static function init(): static
    {
        return new static();
    }

    /**
     * Public clone - prevent cloning
     *
     * @throws RtStatesCloneException
     */
    public function __clone(): void
    {
        throw new RtStatesCloneException('RtStates cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization
     *
     * @throws RtStatesUnserializeException
     */
    public function __wakeup(): void
    {
        throw new RtStatesUnserializeException('RtStates cannot be unserialized');
    }

    /**
     * Debug info
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Add state to collection
     *
     * @param RtState $state State instance to add
     */
    public function add(RtState $state): void
    {
        $this->states[$state->getId()] = $state;
    }

    /**
     * Remove state from collection by ID
     *
     * @param string $id State ID
     */
    public function remove(string $id): void
    {
        unset($this->states[$id]);
    }

    /**
     * Check if state exists in collection
     *
     * @param string $id State ID
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->states[$id]);
    }

    /**
     * Get state by ID
     *
     * @param string $id State ID
     * @return ?RtState
     */
    public function get(string $id): ?RtState
    {
        return $this->states[$id] ?? null;
    }

    /**
     * Clear all states from collection
     */
    public function clear(): void
    {
        $this->states = [];
        $this->index = 0;
        $this->backupIndex = 0;
    }

    /**
     * Convert all states to array
     *
     * @param bool $idAsIndex Use ID as array index
     * @return array
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
     * Get first state
     *
     * @return ?RtState
     */
    public function first(): ?RtState
    {
        $keys = array_keys($this->states);
        $firstKey = $keys[0] ?? null;
        return $firstKey !== null ? $this->states[$firstKey] : null;
    }

    /**
     * Get last state
     *
     * @return ?RtState
     */
    public function last(): ?RtState
    {
        $keys = array_keys($this->states);
        $lastKey = end($keys);
        return $lastKey !== false ? $this->states[$lastKey] : null;
    }

    /**
     * Backup current iterator position
     */
    public function backupIndex(): void
    {
        $this->backupIndex = $this->index;
    }

    /**
     * Restore iterator position from backup
     */
    public function restoreIndex(): void
    {
        $this->index = $this->backupIndex;
    }

    // ==================== ArrayAccess ====================

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->states[$offset]);
    }

    public function offsetGet(mixed $offset): ?RtState
    {
        return $this->states[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof RtState) {
            $this->states[$offset ?? $value->getId()] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->states[$offset]);
    }

    // ==================== Countable ====================

    public function count(): int
    {
        return count($this->states);
    }

    // ==================== Iterator ====================

    public function current(): ?RtState
    {
        $keys = array_keys($this->states);
        if (!isset($keys[$this->index])) {
            return null;
        }
        return $this->states[$keys[$this->index]];
    }

    public function key(): ?string
    {
        $keys = array_keys($this->states);
        return $keys[$this->index] ?? null;
    }

    public function next(): void
    {
        ++$this->index;
    }

    public function rewind(): void
    {
        $this->index = 0;
    }

    public function valid(): bool
    {
        $keys = array_keys($this->states);
        return $this->index < count($keys);
    }
}

<?php

namespace Hilos\Hilos\Runtime\State\Item;

use Hilos\Hilos\Runtime\Exception\State\RtStateUnserializeException;

/**
 * Base class for runtime state objects
 *
 * RtState is the single source of truth for runtime data (analogous to Object_ for database).
 * Each RtState instance holds the actual data in memory.
 * RtItem instances are lightweight wrappers that reference RtState.
 *
 * Unlike database Object_, RtState does not sync to persistent storage.
 * Data lives only in memory for the lifetime of the process.
 *
 * @template TData of array
 */
abstract class RtState
{
    /**
     * State data storage
     *
     * @var array
     */
    protected array $data = [];

    /**
     * Protected constructor - use static factory methods
     */
    protected function __construct()
    {
    }

    /**
     * Protected clone - prevent cloning
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
     * @throws RtStateUnserializeException
     */
    public function __wakeup(): void
    {
        throw new RtStateUnserializeException('RtState cannot be unserialized');
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
     * Get unique identifier for this state
     *
     * Must be implemented by child classes to provide a unique key
     * for storing this state in the collection.
     *
     * @return string Unique identifier
     */
    abstract public function getId(): string;

    /**
     * Convert state to array representation
     *
     * @return array
     */
    abstract public function toArray(): array;
}

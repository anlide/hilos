<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Runtime\Exception\State\RtStatePropertyNotFoundException;
use Hilos\Runtime\Exception\State\RtStateReadOnlyException;
use Hilos\Runtime\Exception\State\RtStateUnserializeException;

/**
 * Base class for runtime state objects
 *
 * RtState is the single source of truth for runtime data (analogous to Object_).
 * Child classes must use typed/private fields and expose read access via __get and/or explicit getters.
 */
abstract class RtState
{
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

    /** @throws RtStatePropertyNotFoundException */
    public function __get(string $name): mixed
    {
        $className = static::class;
        throw new RtStatePropertyNotFoundException("Property [{$name}] does not exist on {$className}");
    }

    /** @throws RtStateReadOnlyException */
    final public function __set(string $name, mixed $value): never
    {
        $className = static::class;
        throw new RtStateReadOnlyException(
            "Cannot set property [{$name}] on {$className}: RtState is read-only from outside."
        );
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
     */
    abstract public static function fromRow(array $row): static;

    /**
     * Apply diff to state fields for RT sync update.
     *
     * Override in child classes to support updates. Default no-op.
     *
     * @param array<string, mixed> $diff Changed fields => values
     */
    public function applyDiff(array $diff): void
    {
    }

    /**
     * Convert state to array representation.
     */
    abstract public function toArray(): array;
}

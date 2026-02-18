<?php

namespace Hilos\Hilos\Runtime\Item;

use Hilos\Hilos\Runtime\Exception\Item\RtItemCloneException;
use Hilos\Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Hilos\Runtime\Exception\Item\RtItemReadOnlyException;
use Hilos\Hilos\Runtime\Exception\Item\RtItemUnserializeException;
use Hilos\Hilos\Runtime\State\Item\RtState;

/**
 * RtItem - read-only wrapper around runtime state (Rt layer).
 *
 * All write operations must go through RtActions.
 * Provides high-level access to transient connection/session data.
 *
 * @template TState of RtState
 * @property-read TState $_state Reference to RtState instance
 */
abstract class RtItem
{
    /** @var TState */
    protected RtState $_state;

    public function __construct(RtState &$state)
    {
        $this->_state = &$state;
    }

    public function getState(): RtState
    {
        return $this->_state;
    }

    public function getId(): string
    {
        return $this->_state->getId();
    }

    /** @throws RtItemCloneException */
    public function __clone(): void
    {
        throw new RtItemCloneException('RtItem cannot be cloned');
    }

    /** @throws RtItemUnserializeException */
    public function __wakeup(): void
    {
        throw new RtItemUnserializeException('RtItem cannot be unserialized');
    }

    /** @throws RtItemPropertyNotFoundException */
    public function __get(string $name)
    {
        $className = static::class;
        throw new RtItemPropertyNotFoundException("Property [{$name}] does not exist on {$className}");
    }

    /** @throws RtItemReadOnlyException */
    final public function __set(string $name, mixed $value): never
    {
        $className = static::class;
        throw new RtItemReadOnlyException(
            "Cannot set property [{$name}] on {$className}: RtItem is read-only. Use actions for modifications."
        );
    }

    abstract public function toArray(): array;

    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

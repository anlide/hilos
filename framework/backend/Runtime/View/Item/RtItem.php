<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemCloneException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\Exception\Item\RtItemReadOnlyException;
use Hilos\Runtime\Exception\Item\RtItemUnserializeException;
use Hilos\Runtime\State\Item\RtState;

/**
 * RtItem - read-only wrapper around runtime state.
 *
 * All write operations must go through RtActions.
 *
 * @template TState of RtState
 * @property-read RtState $_state Reference to RtState instance
 */
abstract class RtItem
{
    /** @var RtState reference to runtime state instance */
    protected RtState $_state;

    /**
     * Creates Rt item wrapper around state reference.
     *
     * @param RtState $state State instance (passed by reference)
     */
    public function __construct(RtState &$state)
    {
        $this->_state = &$state;
    }

    /**
     * Returns the underlying state instance.
     *
     * @return RtState State reference
     */
    public function getState(): RtState
    {
        return $this->_state;
    }

    /**
     * Returns the state identifier.
     *
     * @return string State ID
     */
    public function getId(): string
    {
        return $this->_state->getId();
    }

    /**
     * Forbids cloning (RtItem is read-only).
     *
     * @throws RtItemCloneException Always thrown
     */
    public function __clone(): void
    {
        throw new RtItemCloneException('RtItem cannot be cloned');
    }

    /**
     * Forbids unserialization (RtItem holds state reference).
     *
     * @throws RtItemUnserializeException Always thrown
     */
    public function __wakeup(): void
    {
        throw new RtItemUnserializeException('RtItem cannot be unserialized');
    }

    /**
     * Throws on property access (subclasses override for actual properties).
     *
     * @param string $name Property name
     * @return mixed Property value (never reached, throws instead)
     * @throws RtItemPropertyNotFoundException If the property does not exist
     */
    public function __get(string $name): mixed
    {
        $className = static::class;
        throw new RtItemPropertyNotFoundException("Property [{$name}] does not exist on {$className}");
    }

    /**
     * Forbids property assignment (RtItem is read-only).
     *
     * @param string $name Property name
     * @param mixed $value Value to set (unused)
     * @return never Never returns (always throws)
     * @throws RtItemReadOnlyException Always thrown
     */
    final public function __set(string $name, mixed $value): never
    {
        $className = static::class;
        throw new RtItemReadOnlyException(
            "Cannot set property [{$name}] on {$className}: RtItem is read-only. Use actions for modifications."
        );
    }

    /**
     * Converts item to associative array.
     *
     * @return array<string, mixed> Item data as array
     */
    abstract public function toArray(): array;

    /**
     * Returns debug representation (same as toArray).
     *
     * @return array<string, mixed> Item data for var_dump etc
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

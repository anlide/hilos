<?php

namespace Hilos\Hilos\Runtime\Base;

use Hilos\Exception\Runtime\Item\IdeaRtItemCloneException;
use Hilos\Exception\Runtime\Item\IdeaRtItemPropertyNotFoundException;
use Hilos\Exception\Runtime\Item\IdeaRtItemReadOnlyException;
use Hilos\Exception\Runtime\Item\IdeaRtItemUnserializeException;
use Hilos\Hilos\Runtime\State\Item\RtState;

/**
 * Base class for individual runtime items (read-only wrapper around RtState).
 *
 * All write operations must go through RtActionsBase.
 *
 * @template TState of RtState
 * @property-read TState $_state Reference to RtState instance
 */
abstract class RtItemBase
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

    /** @throws IdeaRtItemCloneException */
    public function __clone(): void
    {
        throw new IdeaRtItemCloneException('RtItem cannot be cloned');
    }

    /** @throws IdeaRtItemUnserializeException */
    public function __wakeup(): void
    {
        throw new IdeaRtItemUnserializeException('RtItem cannot be unserialized');
    }

    /** @throws IdeaRtItemPropertyNotFoundException */
    public function __get(string $name)
    {
        $className = static::class;
        throw new IdeaRtItemPropertyNotFoundException("Property [{$name}] does not exist on {$className}");
    }

    /** @throws IdeaRtItemReadOnlyException */
    final public function __set(string $name, mixed $value): never
    {
        $className = static::class;
        throw new IdeaRtItemReadOnlyException(
            "Cannot set property [{$name}] on {$className}: RtItem is read-only. Use actions for modifications."
        );
    }

    abstract public function toArray(): array;

    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

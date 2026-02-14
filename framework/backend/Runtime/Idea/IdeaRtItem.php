<?php

namespace Hilos\Runtime\Idea;

use Hilos\Exception\Runtime\Item\IdeaRtItemCloneException;
use Hilos\Exception\Runtime\Item\IdeaRtItemPropertyNotFoundException;
use Hilos\Exception\Runtime\Item\IdeaRtItemReadOnlyException;
use Hilos\Exception\Runtime\Item\IdeaRtItemUnserializeException;
use Hilos\Runtime\State\RtState;

/**
 * Base class for individual runtime Idea items
 *
 * IdeaRtItem is a read-only wrapper around RtState (analogous to IdeaItem for database).
 * It stores only a reference to RtState for memory efficiency.
 * All write operations must go through IdeaRtActions.
 *
 * @template TState of RtState
 * @property-read TState $_state Reference to RtState instance
 */
abstract class IdeaRtItem
{
    /**
     * Reference to RtState instance
     *
     * @var TState
     */
    protected RtState $_state;

    /**
     * Constructor - creates IdeaRtItem from RtState instance
     *
     * @param RtState $state RtState instance (reference)
     */
    public function __construct(RtState &$state)
    {
        $this->_state = &$state;
    }

    /**
     * Get underlying RtState reference (for use by IdeaRtCollection when creating filtered views)
     *
     * @return TState
     */
    public function getState(): RtState
    {
        return $this->_state;
    }

    /**
     * Get ID string (for use as array key)
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->_state->getId();
    }

    /**
     * Public clone - prevent cloning
     *
     * Cloning IdeaRtItem would create duplicate references to the same RtState,
     * which could lead to inconsistent state.
     *
     * @throws IdeaRtItemCloneException
     */
    public function __clone(): void
    {
        throw new IdeaRtItemCloneException('IdeaRtItem cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization
     *
     * IdeaRtItem references cannot be safely serialized/unserialized.
     *
     * @throws IdeaRtItemUnserializeException
     */
    public function __wakeup(): void
    {
        throw new IdeaRtItemUnserializeException('IdeaRtItem cannot be unserialized');
    }

    /**
     * Property getter - throws error for undeclared properties
     *
     * Child classes should override this method and call parent::__get() in default case.
     *
     * @param string $name Property name
     * @return never
     * @throws IdeaRtItemPropertyNotFoundException
     */
    public function __get(string $name)
    {
        $className = static::class;
        throw new IdeaRtItemPropertyNotFoundException("Property [{$name}] does not exist on {$className}");
    }

    /**
     * Property setter - prevents modification (read-only)
     *
     * IdeaRtItem is read-only wrapper, properties cannot be modified directly.
     * Use Actions for write operations.
     *
     * @param string $name Property name
     * @param mixed $value Property value
     * @return never
     * @throws IdeaRtItemReadOnlyException
     */
    final public function __set(string $name, mixed $value): never
    {
        $className = static::class;
        throw new IdeaRtItemReadOnlyException(
            "Cannot set property [{$name}] on {$className}: IdeaRtItem is read-only. Use actions for modifications."
        );
    }

    /**
     * Convert to array representation
     *
     * Must be implemented by child classes.
     *
     * @return array
     */
    abstract public function toArray(): array;

    /**
     * Debug info
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

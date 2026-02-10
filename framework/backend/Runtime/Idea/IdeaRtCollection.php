<?php

namespace Hilos\Runtime\Idea;

use ArrayAccess;
use Countable;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionActionsClassException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionCloneException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionDirectSetException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionPropertyNotFoundException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionUnserializeException;
use Hilos\Runtime\State\RtState;
use Hilos\Runtime\State\RtStates;
use Iterator;

/**
 * Base class for runtime Idea collections
 *
 * IdeaRtCollection is a read-only wrapper around RtStates (analogous to IdeaCollection for database).
 * It provides read access to runtime data and write access through Actions.
 *
 * @template T of IdeaRtItem
 * @implements ArrayAccess<string, T>
 * @implements Iterator<string, T>
 */
abstract class IdeaRtCollection implements ArrayAccess, Countable, Iterator
{
    public const string actions = 'actions';

    /**
     * Current iterator position
     *
     * @var int
     */
    private int $position = 0;

    /**
     * Cached IdeaRtItem instances
     *
     * @var array<string, T>
     */
    private array $items = [];

    /**
     * State collection reference (set via setStateCollection)
     *
     * @var ?RtStates
     */
    private ?RtStates $_stateCollection = null;

    /**
     * Collection name (set via setCollectionName)
     *
     * @var ?string
     */
    private ?string $_collectionName = null;

    /**
     * Actions class name (set via setActionsClass)
     *
     * @var ?string
     */
    private ?string $_actionsClass = null;

    /**
     * Cached Actions instance (created lazily)
     *
     * @var ?IdeaRtActions
     */
    private ?IdeaRtActions $_actions = null;

    /**
     * Protected constructor - use static factory methods
     */
    protected function __construct()
    {
    }

    /**
     * Initialize collection
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
     * @throws IdeaRtCollectionCloneException
     */
    public function __clone(): void
    {
        throw new IdeaRtCollectionCloneException('IdeaRtCollection cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization
     *
     * @throws IdeaRtCollectionUnserializeException
     */
    public function __wakeup(): void
    {
        throw new IdeaRtCollectionUnserializeException('IdeaRtCollection cannot be unserialized');
    }

    /**
     * Set state collection reference
     *
     * Called by IdeaRt::setRepresent() when collection is registered.
     *
     * @param RtStates $stateCollection State collection instance (reference)
     */
    public function setStateCollection(RtStates &$stateCollection): void
    {
        $this->_stateCollection = &$stateCollection;
    }

    /**
     * Set collection name
     *
     * Called by IdeaRt::setRepresent() when collection is registered.
     *
     * @param string $name Collection name
     */
    public function setCollectionName(string $name): void
    {
        $this->_collectionName = $name;
    }

    /**
     * Get collection name
     *
     * @return ?string Collection name or null if not set
     */
    public function getCollectionName(): ?string
    {
        return $this->_collectionName;
    }

    /**
     * Set Actions class name
     *
     * Called by IdeaRt::setRepresent() when collection is registered.
     *
     * @param ?string $actionsClass Actions class name
     */
    public function setActionsClass(?string $actionsClass): void
    {
        $this->_actionsClass = $actionsClass;
    }

    /**
     * Get Actions instance
     *
     * Creates Actions instance lazily on first access.
     *
     * @return IdeaRtActions
     * @throws IdeaRtCollectionActionsClassException If Actions class is not set or invalid
     */
    protected function getActions(): IdeaRtActions
    {
        if ($this->_actions === null) {
            if ($this->_actionsClass === null) {
                throw new IdeaRtCollectionActionsClassException(
                    "Actions class is not set for " . static::class
                );
            }

            $class = $this->_actionsClass;
            if (!is_subclass_of($class, IdeaRtActions::class)) {
                throw new IdeaRtCollectionActionsClassException(
                    "Actions class [{$class}] must extend IdeaRtActions"
                );
            }

            $this->_actions = new $class($this);

            // Set callback for creating IdeaRtItem from RtState
            $this->_actions->setCreateRtItemCallback(function (RtState &$state): IdeaRtItem {
                return $this->createRtItem($state);
            });

            // Set callback for cache invalidation
            $this->_actions->setClearCacheCallback(function (): void {
                $this->clearCache();
            });
        }

        return $this->_actions;
    }

    /**
     * Get state collection
     *
     * @return ?RtStates
     */
    public function getStateCollection(): ?RtStates
    {
        return $this->_stateCollection;
    }

    /**
     * Create IdeaRtItem instance from RtState
     *
     * Must be implemented by child classes.
     *
     * @param RtState $state RtState instance (reference)
     * @return T
     */
    abstract protected function createRtItem(RtState &$state): IdeaRtItem;

    /**
     * Get IdeaRtItem for key
     *
     * Uses cached item if available, otherwise creates new instance.
     *
     * @param string $key State ID
     * @return ?T
     */
    protected function getRtItemForKey(string $key): ?IdeaRtItem
    {
        // Use cached item if available
        if (isset($this->items[$key])) {
            return $this->items[$key];
        }

        // Get from state collection
        $stateCollection = $this->getStateCollection();
        if ($stateCollection === null) {
            return null;
        }

        $state = $stateCollection[$key] ?? null;
        if ($state === null) {
            return null;
        }

        // Create item and cache it
        $item = $this->createRtItem($state);
        $this->items[$key] = $item;

        return $item;
    }

    /**
     * Convert to array
     *
     * @param bool $idAsIndex Use ID as array index
     * @return array
     */
    public function toArray(bool $idAsIndex = true): array
    {
        $result = [];

        $stateCollection = $this->getStateCollection();
        if ($stateCollection !== null) {
            foreach ($stateCollection as $key => $state) {
                $item = $this->getRtItemForKey($key);
                if ($item !== null) {
                    $data = $item->toArray();
                    if ($idAsIndex) {
                        $result[$key] = $data;
                    } else {
                        $result[] = $data;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get first item
     *
     * @return ?T
     */
    public function first(): ?IdeaRtItem
    {
        $stateCollection = $this->getStateCollection();
        if ($stateCollection === null) {
            return null;
        }

        $firstState = $stateCollection->first();
        if ($firstState === null) {
            return null;
        }

        return $this->getRtItemForKey($firstState->getId());
    }

    /**
     * Get last item
     *
     * @return ?T
     */
    public function last(): ?IdeaRtItem
    {
        $stateCollection = $this->getStateCollection();
        if ($stateCollection === null) {
            return null;
        }

        $lastState = $stateCollection->last();
        if ($lastState === null) {
            return null;
        }

        return $this->getRtItemForKey($lastState->getId());
    }

    /**
     * Clear cached items
     */
    public function clearCache(): void
    {
        $this->items = [];
        $this->position = 0;
    }

    /**
     * Magic getter for actions
     *
     * @param string $name Property name
     * @return IdeaRtActions
     * @throws IdeaRtCollectionPropertyNotFoundException
     * @throws IdeaRtCollectionActionsClassException
     */
    public function __get(string $name)
    {
        if ($name === self::actions) {
            return $this->getActions();
        }

        throw new IdeaRtCollectionPropertyNotFoundException(
            "Property [{$name}] does not exist on " . static::class
        );
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

    // ==================== ArrayAccess ====================

    /**
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        $stateCollection = $this->getStateCollection();
        return $stateCollection !== null && isset($stateCollection[$offset]);
    }

    /**
     * @param mixed $offset
     * @return ?T
     */
    public function offsetGet(mixed $offset): ?IdeaRtItem
    {
        return $this->getRtItemForKey($offset);
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     * @throws IdeaRtCollectionDirectSetException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new IdeaRtCollectionDirectSetException(
            "Cannot directly set items in collection. Use actions for modifications."
        );
    }

    /**
     * @param mixed $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);

        $stateCollection = $this->getStateCollection();
        if ($stateCollection !== null) {
            $stateCollection->remove($offset);
        }
    }

    // ==================== Countable ====================

    /**
     * @return int
     */
    public function count(): int
    {
        $stateCollection = $this->getStateCollection();
        return $stateCollection !== null ? $stateCollection->count() : 0;
    }

    // ==================== Iterator ====================

    /**
     * @return ?T
     */
    public function current(): ?IdeaRtItem
    {
        $keys = array_keys($this->items);
        if (!isset($keys[$this->position])) {
            return null;
        }
        return $this->items[$keys[$this->position]];
    }

    /**
     * @return ?string
     */
    public function key(): ?string
    {
        $keys = array_keys($this->items);
        return $keys[$this->position] ?? null;
    }

    /**
     * Move iterator forward
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Rewind iterator
     */
    public function rewind(): void
    {
        $this->position = 0;

        // Rebuild items cache from state collection
        $stateCollection = $this->getStateCollection();
        if ($stateCollection !== null) {
            $this->items = [];
            foreach ($stateCollection as $key => $state) {
                $this->items[$key] = $this->createRtItem($state);
                unset($state);
            }
            $stateCollection->rewind();
        }
    }

    /**
     * @return bool
     */
    public function valid(): bool
    {
        $keys = array_keys($this->items);
        return $this->position < count($keys);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use ArrayAccess;
use Countable;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionCloneException;
use Hilos\Runtime\Exception\Collection\RtCollectionDirectSetException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\Exception\Collection\RtCollectionUnserializeException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Actions\Item\RtActions as RtItemActions;
use Hilos\Runtime\View\Item\RtItem;
use Iterator;

/**
 * RtCollection - collection of RtItem instances (view layer).
 *
 * Read-only wrapper around runtime state collection.
 *
 * @template TItem of RtItem
 * @template TActions of RtActions
 * @implements ArrayAccess<string, TItem>
 * @implements Iterator<string, TItem>
 * @property-read TActions $actions
 */
abstract class RtCollection implements ArrayAccess, Countable, Iterator
{
    public const string actions = 'actions';

    /** @var int current iterator position */
    private int $position = 0;

    /** @var array<string, TItem> state ID => RtItem cache */
    private array $items = [];

    /** @var ?RtStates state collection reference */
    private ?RtStates $_stateCollection = null;

    /** @var ?string collection name for truth source and sync */
    private ?string $_collectionName = null;

    /** @var ?class-string<TActions> actions class for lazy init */
    private ?string $_actionsClass = null;

    /** @var ?class-string<RtItemActions> item actions class for each RtItem */
    private ?string $_itemActionsClass = null;

    /** @var ?TActions cached actions instance */
    private ?RtActions $_actions = null;

    /**
     * Protected constructor. Use init() to create instance.
     */
    protected function __construct()
    {
    }

    /**
     * Initialize empty collection.
     *
     * @return static New RtCollection instance
     */
    public static function init(): static
    {
        return new static();
    }

    /**
     * Prevents cloning of RtCollection.
     *
     * @throws RtCollectionCloneException Always, cloning not allowed
     */
    public function __clone(): void
    {
        throw new RtCollectionCloneException('RtCollection cannot be cloned');
    }

    /**
     * Prevents unserialization of RtCollection.
     *
     * @throws RtCollectionUnserializeException Always, unserialization not allowed
     */
    public function __wakeup(): void
    {
        throw new RtCollectionUnserializeException('RtCollection cannot be unserialized');
    }

    /**
     * Set state collection reference.
     *
     * @param RtStates $stateCollection State collection instance
     */
    public function setStateCollection(RtStates &$stateCollection): void
    {
        $this->_stateCollection = &$stateCollection;
    }

    /**
     * Set collection name for truth source and sync.
     *
     * @param string $name Collection name
     */
    public function setCollectionName(string $name): void
    {
        $this->_collectionName = $name;
    }

    /**
     * Get collection name.
     *
     * @return ?string Collection name or null if not set
     */
    public function getCollectionName(): ?string
    {
        return $this->_collectionName;
    }

    /**
     * Set actions class for lazy initialization.
     *
     * @param ?class-string<TActions> $actionsClass Actions class or null
     */
    public function setActionsClass(?string $actionsClass): void
    {
        $this->_actionsClass = $actionsClass;
    }

    /**
     * Set item actions class for lazy initialization on each RtItem.
     *
     * @param ?class-string<RtItemActions> $itemActionsClass
     */
    public function setItemActionsClass(?string $itemActionsClass): void
    {
        $this->_itemActionsClass = $itemActionsClass;
    }

    /**
     * Wires item to this collection (parent ref + item actions class). Call after createRtItem().
     */
    protected function attachItemToCollection(RtItem $item): void
    {
        $item->setCollection($this);
        $item->setItemActionsClass($this->_itemActionsClass);
    }

    /**
     * Get actions instance (creates lazily on first access).
     *
     * @return TActions Actions instance
     * @throws RtCollectionActionsClassException When actions class not set or invalid
     */
    protected function getActions(): RtActions
    {
        if ($this->_actions === null) {
            if ($this->_actionsClass === null) {
                throw new RtCollectionActionsClassException(
                    "Actions class is not set for " . static::class
                );
            }

            $class = $this->_actionsClass;
            if (!is_subclass_of($class, RtActions::class)) {
                throw new RtCollectionActionsClassException(
                    "Actions class [{$class}] must extend RtActions"
                );
            }

            $this->_actions = new $class($this);

            $this->_actions->setCreateRtItemCallback(function (RtState &$state): RtItem {
                $item = $this->createRtItem($state);
                $this->attachItemToCollection($item);

                return $item;
            });

            $this->_actions->setClearCacheCallback(function (): void {
                $this->clearCache();
            });
        }

        return $this->_actions;
    }

    /**
     * Get state collection reference.
     *
     * @return RtStates State collection instance
     * @throws RtActionsStateCollectionNullException When state collection not set
     */
    public function getStateCollection(): RtStates
    {
        if ($this->_stateCollection === null) {
            throw new RtActionsStateCollectionNullException(
                'State collection is null. Call setStateCollection() before using the collection.'
            );
        }
        return $this->_stateCollection;
    }

    /**
     * Creates RtItem from RtState (implemented by child classes).
     *
     * @param RtState $state State instance (reference)
     * @return TItem RtItem wrapper for the state
     */
    abstract protected function createRtItem(RtState &$state): RtItem;

    /**
     * Get RtItem for state key (cached or created).
     *
     * @param string $key State ID
     * @return ?TItem RtItem instance or null if state not found
     */
    protected function getRtItemForKey(string $key): ?RtItem
    {
        if (isset($this->items[$key])) {
            return $this->items[$key];
        }

        $stateCollection = $this->getStateCollection();
        $state = $stateCollection[$key] ?? null;
        if ($state === null) {
            return null;
        }

        $item = $this->createRtItem($state);
        $this->attachItemToCollection($item);
        $this->items[$key] = $item;

        return $item;
    }

    /**
     * Convert collection to array.
     *
     * @param bool $idAsIndex Use state ID as array index
     * @return array<string|int, array<string, mixed>> Items as associative array
     */
    public function toArray(bool $idAsIndex = true): array
    {
        $result = [];
        $stateCollection = $this->getStateCollection();

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

        return $result;
    }

    /**
     * Get first RtItem.
     *
     * @return ?TItem First item or null if empty
     */
    public function first(): ?RtItem
    {
        $stateCollection = $this->getStateCollection();
        $firstState = $stateCollection->first();
        if ($firstState === null) {
            return null;
        }
        return $this->getRtItemForKey($firstState->getId());
    }

    /**
     * Get last RtItem.
     *
     * @return ?TItem Last item or null if empty
     */
    public function last(): ?RtItem
    {
        $stateCollection = $this->getStateCollection();
        $lastState = $stateCollection->last();
        if ($lastState === null) {
            return null;
        }
        return $this->getRtItemForKey($lastState->getId());
    }

    /**
     * Clears cached RtItem instances and resets iterator.
     */
    public function clearCache(): void
    {
        $this->items = [];
        $this->position = 0;
    }

    /**
     * Magic getter for actions property.
     *
     * @param string $name Property name (actions only)
     * @return TActions Actions instance when name is "actions"
     * @throws RtCollectionPropertyNotFoundException When property does not exist
     */
    public function __get(string $name): mixed
    {
        if ($name === self::actions) {
            return $this->getActions();
        }
        throw new RtCollectionPropertyNotFoundException(
            "Property [{$name}] does not exist on " . static::class
        );
    }

    /**
     * Debug info for var_dump.
     *
     * @return array<string|int, array<string, mixed>> Collection as array
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Check if item exists at offset.
     *
     * @param mixed $offset State ID
     * @return bool True if exists
     */
    public function offsetExists(mixed $offset): bool
    {
        $stateCollection = $this->getStateCollection();
        return isset($stateCollection[$offset]);
    }

    /**
     * Get item at offset.
     *
     * @param mixed $offset State ID
     * @return ?TItem Item or null if not found
     */
    public function offsetGet(mixed $offset): ?RtItem
    {
        return $this->getRtItemForKey((string)$offset);
    }

    /**
     * Set item at offset (not supported).
     *
     * @param mixed $offset State ID
     * @param mixed $value Value (ignored)
     * @throws RtCollectionDirectSetException Always, direct set not allowed
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new RtCollectionDirectSetException(
            "Cannot directly set items in collection. Use actions for modifications."
        );
    }

    /**
     * Remove item at offset.
     *
     * @param mixed $offset State ID to remove
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[(string)$offset]);
        $this->getStateCollection()->remove((string)$offset);
    }

    /**
     * Get number of items.
     *
     * @return int Item count
     */
    public function count(): int
    {
        return $this->getStateCollection()->count();
    }

    /**
     * Get current item in iteration.
     *
     * @return ?TItem Current item or null if position invalid
     */
    public function current(): ?RtItem
    {
        $keys = array_keys($this->items);
        if (!isset($keys[$this->position])) {
            return null;
        }
        return $this->items[$keys[$this->position]];
    }

    /**
     * Get current iterator key.
     *
     * @return ?string Current state ID or null if invalid
     */
    public function key(): ?string
    {
        $keys = array_keys($this->items);
        return $keys[$this->position] ?? null;
    }

    /**
     * Advance iterator to next element.
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Reset iterator to first element.
     */
    public function rewind(): void
    {
        $this->position = 0;
        $stateCollection = $this->getStateCollection();
        $this->items = [];
        foreach ($stateCollection as $key => $state) {
            $item = $this->createRtItem($state);
            $this->attachItemToCollection($item);
            $this->items[$key] = $item;
            unset($state);
        }
        $stateCollection->rewind();
    }

    /**
     * Check if current iterator position is valid.
     *
     * @return bool True if position has element
     */
    public function valid(): bool
    {
        $keys = array_keys($this->items);
        return $this->position < count($keys);
    }
}

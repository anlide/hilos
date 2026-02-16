<?php

namespace Hilos\Database\Idea;

use ArrayAccess;
use Countable;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Exception\Database\Object\ObjectGetIdStringNotImplementedException;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Collection\IdeaCollectionActionsClassException;
use Hilos\Exception\Idea\Collection\IdeaCollectionCloneException;
use Hilos\Exception\Idea\Collection\IdeaCollectionDirectSetException;
use Hilos\Exception\Idea\Collection\IdeaCollectionNotManualException;
use Hilos\Exception\Idea\Collection\IdeaCollectionPropertyNotFoundException;
use Hilos\Exception\Idea\Collection\IdeaCollectionUnserializeException;
use Iterator;

/**
 * Base Idea Collection class
 * Provides lazy loading and relation management between Idea items
 *
 * Supports two modes:
 * 1. Automatic mode (default): Wraps ObjectCollection from Idea, lazy loading handled by ObjectCollection
 * 2. Manual mode: Empty collection that can be populated manually with IdeaItem instances
 *
 * IdeaCollection contains array of IdeaItem instances.
 * Each IdeaItem references a specific Object stored in ObjectCollection in Idea.
 *
 * @template T of IdeaItem
 * @implements ArrayAccess<int|string, T>
 * @implements Iterator<int|string, T>
 */
abstract class IdeaCollection implements ArrayAccess, Countable, Iterator
{
    public const string actions = 'actions';

    /**
     * Whether this is a manual collection (empty, populated manually)
     * If false, collection wraps ObjectCollection from IdeaStorage
     */
    protected bool $isManual = false;

    /**
     * Current iterator position
     */
    private int $position = 0;

    /**
     * Cached IdeaItem instances for iteration
     * Key is the primary key ID, value is IdeaItem instance
     *
     * @var array<int|string, T>
     */
    private array $items = [];

    /**
     * Constructor - should be called by child classes via init() or initEmpty()
     */
    protected function __construct()
    {
    }

    /**
     * Initialize collection in automatic mode (wraps ObjectCollection from Idea)
     * Default implementation - child classes can override if needed
     *
     * @return static
     */
    public static function init(): static
    {
        $instance = new static();
        $instance->isManual = false;
        return $instance;
    }

    /**
     * Initialize empty collection in manual mode (populated manually via add())
     * Default implementation - child classes can override if needed
     *
     * @return static
     */
    public static function initEmpty(): static
    {
        $instance = new static();
        $instance->isManual = true;
        return $instance;
    }

    /**
     * Create a manual collection containing only the given item.
     * Use when a single IdeaItem must be represented as an IdeaCollection (e.g. for DTO full payload).
     *
     * @param IdeaItem $item Single item to wrap
     * @return static New manual collection with one item
     * @throws IdeaCollectionNotManualException Never (initEmpty is manual)
     * @throws ObjectGetIdStringNotImplementedException If IdeaItem's Object does not implement getIdString() (required for manual collections to use ID as key)
     */
    public static function fromSingleItem(IdeaItem $item): static
    {
        $collection = static::initEmpty();
        $collection->add($item);
        return $collection;
    }

    /**
     * Public clone - prevent cloning
     *
     * Magic methods in PHP must be public to be called.
     * IdeaCollection instances should not be cloned.
     * @throws IdeaCollectionCloneException
     */
    public function __clone(): void
    {
        throw new IdeaCollectionCloneException('IdeaCollection cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization
     *
     * Magic methods in PHP must be public to be called.
     * IdeaCollection instances cannot be safely unserialized.
     * @throws IdeaCollectionUnserializeException
     */
    public function __wakeup(): void
    {
        throw new IdeaCollectionUnserializeException('IdeaCollection cannot be unserialized');
    }

    /**
     * Object collection reference (set via setObjectCollection)
     * Used in automatic mode to access Object collection
     *
     * @var ?Objects
     */
    private ?Objects $_objectCollection = null;

    /**
     * Actions class name (set via setActionsClass)
     * Used to create Actions instance on demand
     *
     * @var ?string
     */
    private ?string $_actionsClass = null;

    /**
     * Cached Actions instance (created lazily)
     *
     * @var ?IdeaActions
     */
    private ?IdeaActions $_actions = null;

    /**
     * Set Object collection reference
     * Called by Idea::setRepresent() when collection is registered
     *
     * @param Objects $objectCollection Object collection instance (reference)
     */
    public function setObjectCollection(Objects &$objectCollection): void
    {
        $this->_objectCollection = &$objectCollection;
    }

    /**
     * Set Actions class name
     * Called by Idea::setRepresent() when collection is registered
     *
     * @param ?string $actionsClass Actions class name (null to use default)
     */
    public function setActionsClass(?string $actionsClass): void
    {
        $this->_actionsClass = $actionsClass;
    }

    /**
     * Get Actions instance
     * Creates Actions instance lazily on first access
     *
     * @return IdeaActions
     * @throws IdeaCollectionActionsClassException If Actions class is not set and default class cannot be used
     */
    protected function getActions(): IdeaActions
    {
        if ($this->_actions === null) {
            $class = $this->_actionsClass ?? IdeaActions::class;
            if (!is_subclass_of($class, IdeaActions::class)) {
                throw new IdeaCollectionActionsClassException("Actions class [{$class}] must extend IdeaActions");
            }
            $this->_actions = new $class($this);

            // Set callback for creating IdeaItem from Object
            // This allows Actions to create IdeaItem instances without accessing protected createIdea()
            $this->_actions->setCreateIdeaCallback(function (Object_ &$object): IdeaItem {
                return $this->createIdea($object);
            });

            // Set callback for mass-mutations cache invalidation
            $this->_actions->setClearCacheCallback(function (): void {
                $this->clearCache();
            });
        }
        return $this->_actions;
    }

    /**
     * Get ObjectCollection
     * Returns the Object collection reference set via setObjectCollection()
     * Returns null for manual collections
     *
     * Public method to allow Actions and child classes to access ObjectCollection.
     * Returns reference to storage - modifications will affect the stored collection.
     *
     * Note: In PHP, objects are passed by reference, so modifications to the returned
     * object will affect the original object stored in Idea::_objectCollections
     *
     * @return ?Objects ObjectCollection instance, or null for manual collections
     */
    public function getObjectCollection(): ?Objects
    {
        return $this->_objectCollection;
    }

    /**
     * Create Idea instance from Object
     * Must be implemented by child classes
     *
     * @param Object_ $object Object instance (reference)
     * @return T
     */
    abstract protected function createIdea(Object_ &$object): IdeaItem;

    /**
     * Add IdeaItem to manual collection
     * Only works for manual collections (created via initEmpty())
     *
     * @param T $item IdeaItem instance to add
     *
     * @throws IdeaCollectionNotManualException If collection is not manual, or item has no ID
     * @throws ObjectGetIdStringNotImplementedException If IdeaItem's Object does not implement getIdString() (required for manual collections to use ID as key)
     */
    public function add(IdeaItem $item): void
    {
        if (!$this->isManual) {
            throw new IdeaCollectionNotManualException("Can only add items to manual collections (created via initEmpty())");
        }

        $this->items[$item->getIdString()] = $item;
    }

    /**
     * Return a new manual collection containing all items from this collection plus all items from $other that are not already present (by ID).
     * Used when appending to a full snapshot in DTO.
     *
     * @param IdeaCollection $other Collection whose items to merge in (only those not already in $this)
     * @return static New manual collection
     * @throws IdeaCollectionNotManualException Never (initEmpty is manual)
     * @throws ObjectGetIdStringNotImplementedException If an item's Object does not implement getIdString()
     */
    public function mergeWith(IdeaCollection $other): static
    {
        $result = static::initEmpty();
        foreach ($this as $item) {
            $result->add($item);
        }
        foreach ($other as $key => $item) {
            if (!$result->offsetExists($key)) {
                $result->add($item);
            }
        }
        return $result;
    }

    /**
     * Get Idea instance for object at key
     * Supports lazy loading from Object collection (for automatic mode)
     * Uses cached IdeaItem if available, otherwise creates new instance
     *
     * @param int|string $key Primary key ID
     * @return ?T
     */
    protected function getIdeaForKey(int|string $key): ?IdeaItem
    {
        // Use cached IdeaItem if available
        if (isset($this->items[$key])) {
            return $this->items[$key];
        }

        // For manual collections, return null if not in cache
        if ($this->isManual) {
            return null;
        }

        // For automatic collections, get from ObjectCollection (triggers lazy loading if enabled)
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            return null;
        }

        $object = $objectCollection[$key] ?? null;
        if ($object === null) {
            return null;
        }

        // Create IdeaItem instance and cache it
        $ideaItem = $this->createIdea($object);
        $this->items[$key] = $ideaItem;

        return $ideaItem;
    }

    /**
     * Convert to array
     *
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend When true, exclude fields that must not be sent to frontend (e.g. sessionToken)
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
    {
        $result = [];

        if ($this->isManual) {
            // For manual collections, iterate over cached items
            foreach ($this->items as $key => $idea) {
                $data = $idea->toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);
                if ($idAsIndex) {
                    $result[$key] = $data;
                } else {
                    $result[] = $data;
                }
            }
        } else {
            // For automatic collections, iterate over ObjectCollection
            $objectCollection = $this->getObjectCollection();
            if ($objectCollection !== null) {
                foreach ($objectCollection as $key => $object) {
                    $idea = $this->getIdeaForKey($key);
                    if ($idea !== null) {
                        $data = $idea->toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);
                        if ($idAsIndex) {
                            $result[$key] = $data;
                        } else {
                            $result[] = $data;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Filter collection by callback
     * Returns a new manual collection with filtered items
     * Note: For lazy-loaded collections, this may trigger full load
     *
     * @param callable $callback Callback function (IdeaItem, key) => bool
     * @return static New filtered manual collection
     * @throws IdeaCollectionNotManualException If trying to add to non-manual collection
     * @throws DatabaseException If IdeaItem has no associated Object ID
     */
    public function filter(callable $callback): static
    {
        // Create new empty manual collection
        $filtered = static::initEmpty();

        if ($this->isManual) {
            // For manual collections, filter cached items
            foreach ($this->items as $key => $idea) {
                if ($callback($idea, $key)) {
                    $filtered->add($idea);
                }
            }
        } else {
            // For automatic collections, iterate and filter
            $objectCollection = $this->getObjectCollection();
            if ($objectCollection !== null) {
                // For lazy collections with BATCH strategy, trigger full load
                if ($objectCollection->allowLazyLoading &&
                    $objectCollection->getLazyStrategy() === Objects::LAZY_STRATEGY_BATCH &&
                    !$objectCollection->isAllLoaded()) {
                    $objectCollection->preloadAll();
                }

                // Filter objects and create Idea instances
                foreach ($objectCollection as $key => $object) {
                    $idea = $this->getIdeaForKey($key);
                    if ($idea !== null && $callback($idea, $key)) {
                        $filtered->add($idea);
                    }
                }
            }
        }

        return $filtered;
    }

    /**
     * Map collection
     *
     * @param callable(T, int|string): mixed $callback Callback (idea, key) => any
     * @return array<int|string, mixed>
     */
    public function map(callable $callback): array
    {
        $result = [];
        foreach ($this as $key => $idea) {
            $result[$key] = $callback($idea, $key);
        }
        return $result;
    }

    /**
     * Get first Idea
     *
     * @return ?T
     */
    public function first(): ?IdeaItem
    {
        if ($this->isManual) {
            $keys = array_keys($this->items);
            $firstKey = $keys[0] ?? null;
            return $firstKey !== null ? $this->items[$firstKey] : null;
        }

        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            return null;
        }

        $firstObject = $objectCollection->first();
        if ($firstObject === null) {
            return null;
        }

        $firstKey = array_key_first(iterator_to_array($objectCollection));
        return $firstKey !== null ? $this->getIdeaForKey($firstKey) : null;
    }

    /**
     * Get last Idea
     *
     * @return ?T
     */
    public function last(): ?IdeaItem
    {
        if ($this->isManual) {
            $keys = array_keys($this->items);
            $lastKey = end($keys);
            return $lastKey !== false ? $this->items[$lastKey] : null;
        }

        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            return null;
        }

        $lastObject = $objectCollection->last();
        if ($lastObject === null) {
            return null;
        }

        $keys = array_keys(iterator_to_array($objectCollection));
        $lastKey = end($keys);
        return $lastKey !== false ? $this->getIdeaForKey($lastKey) : null;
    }

    /**
     * Backup current iterator position
     */
    private int $savedPosition = 0;

    /**
     * Backup current iterator position (Idea and Objects).
     *
     * Useful when you need to iterate and then restore cursor.
     */
    public function backupIndex(): void
    {
        $this->savedPosition = $this->position;
        if (!$this->isManual) {
            $objectCollection = $this->getObjectCollection();
            $objectCollection?->backupIndex();
        }
    }

    /**
     * Restore iterator position (Idea and Objects) from backup.
     */
    public function restoreIndex(): void
    {
        $this->position = $this->savedPosition;
        if (!$this->isManual) {
            $objectCollection = $this->getObjectCollection();
            $objectCollection?->restoreIndex();
        }
    }

    /**
     * Check if element exists at offset.
     *
     * For automatic collections, may trigger lazy-load existence check.
     *
     * @param mixed $offset Primary key ID
     */
    public function offsetExists(mixed $offset): bool
    {
        // For manual collections, check cached items
        if ($this->isManual) {
            return isset($this->items[$offset]);
        }

        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            return false;
        }

        // If object is already loaded, it exists
        if (isset($objectCollection[$offset])) {
            return true;
        }

        // If lazy loading is enabled, try to load the object
        // This will check if object exists in database
        if ($objectCollection->allowLazyLoading) {
            $object = $objectCollection[$offset];
            return $object !== null;
        }

        return false;
    }

    /**
     * @param mixed $offset Primary key ID
     * @return ?T
     */
    public function offsetGet(mixed $offset): ?IdeaItem
    {
        return $this->getIdeaForKey($offset);
    }

    /**
     * @throws IdeaCollectionDirectSetException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new IdeaCollectionDirectSetException("Cannot directly set Idea instances in collection");
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);

        if (!$this->isManual) {
            $objectCollection = $this->getObjectCollection();
            if ($objectCollection !== null) {
                unset($objectCollection[$offset]);
            }
        }
    }

    /**
     * Magic getter for actions
     *
     * @param string $name Property name
     * @return IdeaActions
     * @throws IdeaCollectionPropertyNotFoundException
     * @throws IdeaCollectionActionsClassException
     */
    public function __get(string $name)
    {
        if ($name === self::actions) {
            return $this->getActions();
        }

        throw new IdeaCollectionPropertyNotFoundException("Property [{$name}] does not exist on " . static::class);
    }

    /**
     * Get number of elements in collection.
     *
     * For automatic collections, delegates to underlying ObjectCollection.
     */
    public function count(): int
    {
        if ($this->isManual) {
            return count($this->items);
        }

        $objectCollection = $this->getObjectCollection();
        return $objectCollection !== null ? $objectCollection->count() : 0;
    }

    /**
     * Get current element.
     *
     * @return ?T
     */
    public function current(): ?IdeaItem
    {
        // Get keys array for current position
        $keys = array_keys($this->items);
        if (!isset($keys[$this->position])) {
            return null;
        }

        $key = $keys[$this->position];
        return $this->items[$key];
    }

    /**
     * Get current iterator key.
     */
    public function key(): mixed
    {
        // Get keys array for current position
        $keys = array_keys($this->items);
        return $keys[$this->position] ?? null;
    }

    /**
     * Move iterator forward.
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Rewind iterator.
     *
     * For automatic collections, rebuilds local Idea cache from ObjectCollection.
     */
    public function rewind(): void
    {
        $this->position = 0;

        if ($this->isManual) {
            // For manual collections, items are already in $this->items
            // No need to rebuild
            return;
        }

        // For automatic collections, build items cache from ObjectCollection
        // This creates IdeaItem instances for all currently loaded Objects
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection !== null) {
            $this->items = [];
            foreach ($objectCollection as $key => $object) {
                $this->items[$key] = $this->createIdea($object);
                unset($object);
            }
            $objectCollection->rewind();
        }
    }

    /**
     * Check if current iterator position is valid.
     */
    public function valid(): bool
    {
        $keys = array_keys($this->items);
        return $this->position < count($keys);
    }

    /**
     * Debug info
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Clear cached IdeaItem instances.
     *
     * Needed for mass-mutations on underlying ObjectCollection (e.g. deleteAll()),
     * so IdeaCollection doesn't return stale items from its internal cache.
     */
    public function clearCache(): void
    {
        $this->items = [];
        $this->position = 0;
        $this->savedPosition = 0;
    }
}

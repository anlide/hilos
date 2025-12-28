<?php

namespace Hilos\Database\Idea;

use ArrayAccess;
use Countable;
use Hilos\Exception\DatabaseException;
use Iterator;
use Hilos\Database\Object\Object_;
use Hilos\Database\Object\Objects;
use RuntimeException;

/**
 * Base Idea Collection class
 * Provides lazy loading and relation management between Idea items
 *
 * Supports two modes:
 * 1. Automatic mode (default): Wraps ObjectCollection from IdeaStorage, lazy loading handled by ObjectCollection
 * 2. Manual mode: Empty collection that can be populated manually with IdeaItem instances
 *
 * IdeaCollection contains array of IdeaItem instances.
 * Each IdeaItem references a specific Object stored in ObjectCollection in IdeaStorage.
 *
 * @template T of IdeaItem
 * @implements ArrayAccess<int|string, T>
 * @implements Iterator<int|string, T>
 */
abstract class IdeaCollection implements ArrayAccess, Countable, Iterator
{
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
     * @var array<int|string, IdeaItem>
     */
    private array $items = [];

    /**
     * Constructor - should be called by child classes via init() or initEmpty()
     */
    protected function __construct()
    {
    }

    /**
     * Initialize collection in automatic mode (wraps ObjectCollection from IdeaStorage)
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
     * Public clone - prevent cloning
     *
     * Magic methods in PHP must be public to be called.
     * IdeaCollection instances should not be cloned.
     */
    public function __clone(): void
    {
        throw new RuntimeException('IdeaCollection cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization
     *
     * Magic methods in PHP must be public to be called.
     * IdeaCollection instances cannot be safely unserialized.
     */
    public function __wakeup(): void
    {
        throw new RuntimeException('IdeaCollection cannot be unserialized');
    }

    /**
     * Get ObjectCollection from IdeaStorage
     * Must be implemented by child classes to return the appropriate ObjectCollection
     * Returns null for manual collections
     *
     * @return Objects|null ObjectCollection instance from IdeaStorage, or null for manual collections
     */
    abstract protected function getObjectCollection(): ?Objects;

    /**
     * Create Idea instance from Object
     * Must be implemented by child classes
     *
     * @param Object_ $object Object instance (reference)
     * @return IdeaItem
     */
    abstract protected function createIdea(Object_ &$object): IdeaItem;

    /**
     * Get key from IdeaItem (extracts ID from Object)
     * 
     * @param IdeaItem $item IdeaItem instance
     * @return int|string|null Primary key ID
     */
    protected function getKeyFromItem(IdeaItem $item): int|string|null
    {
        // Access Object through reflection to get ID
        // Note: This assumes Object has 'id' property accessible via magic getter
        $reflection = new \ReflectionClass($item);
        $objectProperty = $reflection->getProperty('_object');
        $objectProperty->setAccessible(true);
        $object = $objectProperty->getValue($item);
        
        // Get ID from Object (assuming Object has 'id' property)
        return $object->id ?? null;
    }

    /**
     * Add IdeaItem to manual collection
     * Only works for manual collections (created via initEmpty())
     *
     * @param IdeaItem $item IdeaItem instance to add
     * @throws DatabaseException If collection is not manual
     */
    public function add(IdeaItem $item): void
    {
        if (!$this->isManual) {
            throw new DatabaseException("Can only add items to manual collections (created via initEmpty())");
        }

        $key = $this->getKeyFromItem($item);
        if ($key === null) {
            throw new DatabaseException("Cannot add IdeaItem without ID");
        }

        $this->items[$key] = $item;
    }

    /**
     * Add Object to manual collection (creates IdeaItem automatically)
     * Only works for manual collections (created via initEmpty())
     *
     * @param Object_ $object Object instance (reference)
     * @throws DatabaseException If collection is not manual
     */
    public function addFromObject(Object_ &$object): void
    {
        if (!$this->isManual) {
            throw new DatabaseException("Can only add objects to manual collections (created via initEmpty())");
        }

        $ideaItem = $this->createIdea($object);
        $this->add($ideaItem);
    }

    /**
     * Get Idea instance for object at key
     * Supports lazy loading from Object collection (for automatic mode)
     * Uses cached IdeaItem if available, otherwise creates new instance
     *
     * @param int|string $key Primary key ID
     * @return IdeaItem|null
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
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        $result = [];

        if ($this->isManual) {
            // For manual collections, iterate over cached items
            foreach ($this->items as $key => $idea) {
                $data = $idea->toArray($withId, $idAsIndex, $withBridges, $withCalculation);
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
                        $data = $idea->toArray($withId, $idAsIndex, $withBridges, $withCalculation);
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
                    $objectCollection->_lazyStrategy === Objects::LAZY_STRATEGY_BATCH &&
                    !$objectCollection->_allLoaded) {
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

    public function backupIndex(): void
    {
        $this->savedPosition = $this->position;
        if (!$this->isManual) {
            $objectCollection = $this->getObjectCollection();
            if ($objectCollection !== null) {
                $objectCollection->backupIndex();
            }
        }
    }

    public function restoreIndex(): void
    {
        $this->position = $this->savedPosition;
        if (!$this->isManual) {
            $objectCollection = $this->getObjectCollection();
            if ($objectCollection !== null) {
                $objectCollection->restoreIndex();
            }
        }
    }

    // ArrayAccess implementation
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

    public function offsetGet(mixed $offset): ?IdeaItem
    {
        return $this->getIdeaForKey($offset);
    }

    /**
     * @throws DatabaseException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new DatabaseException("Cannot directly set Idea instances in collection");
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

    // Countable implementation
    public function count(): int
    {
        if ($this->isManual) {
            return count($this->items);
        }

        $objectCollection = $this->getObjectCollection();
        return $objectCollection !== null ? $objectCollection->count() : 0;
    }

    // Iterator implementation
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

    public function key(): mixed
    {
        // Get keys array for current position
        $keys = array_keys($this->items);
        return $keys[$this->position] ?? null;
    }

    public function next(): void
    {
        ++$this->position;
    }

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
            }
            $objectCollection->rewind();
        }
    }

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
}

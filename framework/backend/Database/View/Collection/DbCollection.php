<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use ArrayAccess;
use Countable;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Collection\CloneException;
use Hilos\Database\Exception\View\Collection\DirectSetException;
use Hilos\Database\Exception\View\Collection\PropertyNotFoundException;
use Hilos\Database\Exception\View\Collection\UnserializeException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Item\DbItem;
use InvalidArgumentException;
use Iterator;

/**
 * Base Db collection: lazy loading and relation management between Db items.
 *
 * Supports two modes:
 * 1. Automatic mode (default): Wraps ObjectCollection from storage, lazy loading handled by ObjectCollection
 * 2. Manual mode: Empty collection that can be populated manually with DbItem instances
 *
 * DbCollection contains array of DbItem instances.
 * Each DbItem references a specific Object stored in ObjectCollection.
 *
 * @template T of DbItem
 * @template TObjectCollection of Objects
 * @implements ArrayAccess<int|string, T>
 * @implements Iterator<int|string, T>
 * @property-read Objects $objectCollection Object collection shortcut (throws if manual)
 */
abstract class DbCollection implements ArrayAccess, Countable, Iterator
{
    public const string actions = 'actions';
    public const string objectCollection = 'objectCollection';
    public const string itemActionsClass = 'itemActionsClass';

    /** @var class-string<T> */
    public const string DB_ITEM_CLASS = '';

    /** @var class-string<TObjectCollection> */
    public const string OBJECT_COLLECTION_CLASS = '';

    /**
     * Whether this is a manual collection (empty, populated manually)
     * If false, collection wraps ObjectCollection from storage
     */
    protected bool $isManual = false;

    /**
     * Current iterator position
     */
    private int $position = 0;

    /**
     * Cached DbItem instances for iteration
     * Key is the primary key ID, value is DbItem instance
     *
     * @var DbItem
     */
    private array $items = [];

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
     * Item actions class name (set via setItemActionsClass).
     * Used to create Actions instance for each DbItem on demand.
     *
     * @var ?string
     */
    private ?string $_itemActionsClass = null;

    /**
     * Cached Actions instance (created lazily)
     *
     * @var ?DbActions<T, TObjectCollection>
     */
    private ?DbActions $_actions = null;

    /**
     * Backup current iterator position
     */
    private int $savedPosition = 0;

    /**
     * Constructor - should be called by child classes via init() or initEmpty()
     */
    protected function __construct()
    {
    }

    /**
     * Initialize collection in automatic mode (wraps ObjectCollection)
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
     * Use when a single DbItem must be represented as a DbCollection (e.g. for DTO full payload).
     *
     * @param DbItem $item Single item to wrap
     * @return static New manual collection with one item
     * @throws ObjectGetIdStringNotImplementedException If DbItem's Object does not implement getIdString() (required for manual collections to use ID as key)
     * @throws CollectionNotManualException If collection is not manual (add() is only supported for manual collections, as it uses ID as key)
     */
    public static function fromSingleItem(DbItem $item): static
    {
        $collection = static::initEmpty();
        $collection->add($item);
        return $collection;
    }

    /**
     * Public clone - prevent cloning
     * DbCollection instances should not be cloned.
     *
     * @throws CloneException
     */
    public function __clone(): void
    {
        throw new CloneException('Db collection cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization
     * DbCollection instances cannot be safely unserialized.
     *
     * @throws UnserializeException
     */
    public function __wakeup(): void
    {
        throw new UnserializeException('Db collection cannot be unserialized');
    }

    /**
     * Set Object collection reference
     * Called when collection is registered
     *
     * @param Objects $objectCollection Object collection instance (reference)
     */
    public function setObjectCollection(Objects &$objectCollection): void
    {
        $this->_objectCollection = &$objectCollection;
    }

    /**
     * Set Actions class name
     * Called when collection is registered
     *
     * @param ?string $actionsClass Actions class name (null to use default)
     */
    public function setActionsClass(?string $actionsClass): void
    {
        $this->_actionsClass = $actionsClass;
    }

    /**
     * Set Item Actions class name.
     * Called when collection is registered.
     *
     * @param ?string $itemActionsClass Item actions class name
     */
    public function setItemActionsClass(?string $itemActionsClass): void
    {
        $this->_itemActionsClass = $itemActionsClass;
    }

    /**
     * Get Actions instance
     * Creates Actions instance lazily on first access
     *
     * @return DbActions<T, TObjectCollection>
     * @throws ActionsClassException If Actions class is not set and default class cannot be used
     */
    protected function getActions(): DbActions
    {
        if ($this->_actions === null) {
            $class = $this->_actionsClass ?? DbActions::class;
            if (!is_subclass_of($class, DbActions::class)) {
                throw new ActionsClassException("Actions class [{$class}] must extend DbActions");
            }
            $this->_actions = new $class($this);
            $this->_actions->setCreateDbItemCallback(function (Object_ &$object): DbItem {
                return $this->createDbItem($object);
            });
            $this->_actions->setClearCacheCallback(function (): void {
                $this->clearCache();
            });
        }
        return $this->_actions;
    }

    /**
     * Get ObjectCollection
     * Returns the Object collection reference set via setObjectCollection()
     * Returns null for manual collections.
     * Public method to allow Actions and child classes to access ObjectCollection.
     * Modifications to the returned object will affect the stored collection.
     *
     * @return ?Objects ObjectCollection instance, or null for manual collections
     */
    public function getObjectCollection(): ?Objects
    {
        /** @var ?Objects $objectCollection */
        $objectCollection = $this->_objectCollection;
        return $objectCollection;
    }

    /**
     * Create DbItem instance from Object.
     * Uses DB_ITEM_CLASS and OBJECT_COLLECTION_CLASS constants defined by child classes.
     *
     * @param Object_ $object Object instance (reference)
     * @return DbItem
     */
    protected function createDbItem(Object_ &$object): DbItem
    {
        $itemClass = static::DB_ITEM_CLASS;
        $objectCollectionClass = static::OBJECT_COLLECTION_CLASS;
        if ($itemClass === '' || $objectCollectionClass === '') {
            throw new \LogicException(static::class . ' must define DB_ITEM_CLASS and OBJECT_COLLECTION_CLASS constants');
        }
        $objectClass = $objectCollectionClass::OBJECT_CLASS;
        if (!($object instanceof $objectClass)) {
            throw new InvalidArgumentException("Object must be instance of {$objectClass}");
        }
        $item = new $itemClass($object);
        $item->setCollection($this);
        $item->setActionsClass($this->_itemActionsClass);
        return $item;
    }

    /**
     * Add DbItem to manual collection
     * Only works for manual collections (created via initEmpty())
     *
     * @param DbItem $item DbItem instance to add
     * @throws CollectionNotManualException If collection is not manual, or item has no ID
     * @throws ObjectGetIdStringNotImplementedException If DbItem's Object does not implement getIdString() (required for manual collections to use ID as key)
     */
    public function add(DbItem $item): void
    {
        if (!$this->isManual) {
            throw new CollectionNotManualException("Can only add items to manual collections (created via initEmpty())");
        }
        $this->items[$item->getIdString()] = $item;
    }

    /**
     * Return a new manual collection containing all items from this collection plus all items from $other that are not already present (by ID).
     * Used when appending to a full snapshot in DTO.
     *
     * @param DbCollection $other Collection whose items to merge in (only those not already in $this)
     * @return static New manual collection
     * @throws ObjectGetIdStringNotImplementedException If an item's Object does not implement getIdString()
     * @throws CollectionNotManualException If this collection is not manual (merging is only supported for manual collections, as it returns a new manual collection)
     */
    public function mergeWith(DbCollection $other): static
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
     * Get DbItem instance for object at key
     * Supports lazy loading from Object collection (for automatic mode)
     * Uses cached DbItem if available, otherwise creates new instance
     *
     * @param int|string $key Primary key ID
     * @return ?T DbItem instance for object at key, or null if not found (e.g. deleted)
     */
    protected function getItemForKey(int|string $key): ?DbItem
    {
        if (isset($this->items[$key])) {
            return $this->items[$key];
        }
        if ($this->isManual) {
            return null;
        }
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            return null;
        }
        $object = $objectCollection[$key] ?? null;
        if ($object === null) {
            return null;
        }
        $item = $this->createDbItem($object);
        $this->items[$key] = $item;
        return $item;
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
            foreach ($this->items as $key => $item) {
                $data = $item->toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);
                if ($idAsIndex) {
                    $result[$key] = $data;
                } else {
                    $result[] = $data;
                }
            }
        } else {
            $objectCollection = $this->getObjectCollection();
            if ($objectCollection !== null) {
                foreach ($objectCollection as $key => $object) {
                    $item = $this->getItemForKey($key);
                    if ($item !== null) {
                        $data = $item->toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);
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
     * @param callable $callback Callback function (DbItem, key) => bool
     * @return static New filtered manual collection
     * @throws DatabaseException If DbItem has no associated Object ID
     * @throws CollectionNotManualException If collection is not manual (filtering is only supported for manual collections, as it returns a new manual collection)
     */
    public function filter(callable $callback): static
    {
        $filtered = static::initEmpty();
        if ($this->isManual) {
            foreach ($this->items as $key => $item) {
                if ($callback($item, $key)) {
                    $filtered->add($item);
                }
            }
        } else {
            $objectCollection = $this->getObjectCollection();
            if ($objectCollection !== null) {
                if ($objectCollection->allowLazyLoading &&
                    $objectCollection->getLazyStrategy() === Objects::LAZY_STRATEGY_BATCH &&
                    !$objectCollection->isAllLoaded()) {
                    $objectCollection->preloadAll();
                }
                foreach ($objectCollection as $key => $object) {
                    $item = $this->getItemForKey($key);
                    if ($item !== null && $callback($item, $key)) {
                        $filtered->add($item);
                    }
                }
            }
        }
        return $filtered;
    }

    /**
     * Map collection
     *
     * @param callable(T, int|string): mixed $callback Callback (item, key) => any
     * @return array<int|string, mixed>
     */
    public function map(callable $callback): array
    {
        $result = [];
        foreach ($this as $key => $item) {
            $result[$key] = $callback($item, $key);
        }
        return $result;
    }

    /**
     * Get first DbItem
     *
     * @return ?DbItem
     */
    public function first(): ?DbItem
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
        return $firstKey !== null ? $this->getItemForKey($firstKey) : null;
    }

    /**
     * Get last DbItem
     *
     * @return ?DbItem
     */
    public function last(): ?DbItem
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
        return $lastKey !== false ? $this->getItemForKey($lastKey) : null;
    }

    /**
     * Backup current iterator position (DbItems and Objects).
     * Useful when you need to iterate and then restore cursor.
     */
    public function backupIndex(): void
    {
        $this->savedPosition = $this->position;
        if (!$this->isManual) {
            $this->getObjectCollection()?->backupIndex();
        }
    }

    /**
     * Restore iterator position (DbItems and Objects) from backup.
     */
    public function restoreIndex(): void
    {
        $this->position = $this->savedPosition;
        if (!$this->isManual) {
            $this->getObjectCollection()?->restoreIndex();
        }
    }

    /**
     * Check if element exists at offset.
     * For automatic collections, may trigger lazy-load existence check.
     *
     * @param mixed $offset Primary key ID
     */
    public function offsetExists(mixed $offset): bool
    {
        if ($this->isManual) {
            return isset($this->items[$offset]);
        }
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            return false;
        }
        if (isset($objectCollection[$offset])) {
            return true;
        }
        if ($objectCollection->allowLazyLoading) {
            $object = $objectCollection[$offset];
            return $object !== null;
        }
        return false;
    }

    /**
     * @param mixed $offset Primary key ID
     * @return ?DbItem
     */
    public function offsetGet(mixed $offset): ?DbItem
    {
        return $this->getItemForKey($offset);
    }

    /**
     * @throws DirectSetException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new DirectSetException("Cannot directly set items in collection");
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
     * Magic getter for actions and objectCollection.
     *
     * @param string $name Property name
     * @return DbActions<T, TObjectCollection>|Objects
     * @throws PropertyNotFoundException
     * @throws ActionsClassException
     * @throws ObjectCollectionNullException
     */
    public function __get(string $name)
    {
        return match ($name) {
            self::actions => $this->getActions(),
            self::objectCollection => $this->getObjectCollection()
                ?? throw new ObjectCollectionNullException("ObjectCollection is null (manual collection)"),
            default => throw new PropertyNotFoundException("Property [{$name}] does not exist on " . static::class),
        };
    }

    /**
     * Get number of elements in collection.
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
     * @return ?DbItem
     */
    public function current(): ?DbItem
    {
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
            return;
        }
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection !== null) {
            $this->items = [];
            foreach ($objectCollection as $key => $object) {
                $this->items[$key] = $this->createDbItem($object);
                unset($object);
            }
            $objectCollection->rewind();
        }
    }

    public function valid(): bool
    {
        $keys = array_keys($this->items);
        return $this->position < count($keys);
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    public function clearCache(): void
    {
        $this->items = [];
        $this->position = 0;
        $this->savedPosition = 0;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\DbActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\CloneException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Exception\View\Item\ReadOnlyException;
use Hilos\Database\Exception\View\Item\UnserializeException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Base class for Db items (read-only wrappers around Object instances).
 *
 * High-level access with lazy loading support for relationships.
 * DbItem stores only reference to Object for memory efficiency.
 *
 * @template TObject of Object_
 * @property-read mixed $id Primary key value
 * @property-read DbActions<DbItem, TObject> $actions Item actions for write operations
 */
abstract class DbItem
{
    public const string actions = 'actions';
    public const string object = '_object';

    /**
     * Reference to Object instance
     * Object is stored in ObjectCollection in storage
     *
     * @var TObject
     */
    protected Object_ $_object;

    /**
     * Parent DbCollection for this item.
     *
     * @var ?DbCollection<DbItem, Objects>
     */
    protected ?DbCollection $_collection = null;

    /**
     * Item actions class for lazy initialization.
     *
     * @var ?class-string<DbActions>
     */
    private ?string $_actionsClass = null;

    /**
     * Cached item actions instance.
     *
     * @var ?DbActions<DbItem, Object_>
     */
    private ?DbActions $_actions = null;

    /**
     * Creates DbItem from Object instance.
     *
     * @param Object_ $object Object instance (reference)
     */
    public function __construct(Object_ &$object)
    {
        $this->_object = &$object;
    }

    /**
     * Get ID as string (for use as array key)
     * Delegates to underlying Object instance.
     * Supports composite keys via Object_::getIdString().
     *
     * @throws ObjectGetIdStringNotImplementedException If Object does not implement getIdString()
     */
    public function getIdString(): string
    {
        return $this->_object->getIdString();
    }

    /**
     * Get underlying object reference.
     *
     * @return TObject
     */
    public function getObject(): Object_
    {
        return $this->_object;
    }

    /**
     * Set parent DbCollection reference.
     *
     * @param DbCollection<DbItem, Objects> $collection Parent collection instance
     */
    public function setCollection(DbCollection $collection): void
    {
        $this->_collection = $collection;
    }

    /**
     * Get parent DbCollection reference.
     *
     * @return ?DbCollection<DbItem, Objects>
     */
    public function getCollection(): ?DbCollection
    {
        return $this->_collection;
    }

    /**
     * Get object collection from parent collection.
     *
     * @return ?Objects Object collection or null if no parent
     */
    public function getObjectCollection(): ?Objects
    {
        return $this->_collection?->getObjectCollection();
    }

    /**
     * Set item actions class for lazy initialization.
     *
     * @param ?class-string<DbActions> $actionsClass Item actions class or null
     */
    public function setActionsClass(?string $actionsClass): void
    {
        $this->_actionsClass = $actionsClass;
    }

    /**
     * Get item actions instance.
     *
     * @return DbActions<DbItem, Object_> Item actions instance
     * @throws ActionsClassException If actions class not set or invalid
     */
    protected function getActions(): DbActions
    {
        if ($this->_actions === null) {
            $class = $this->_actionsClass;
            if ($class === null) {
                throw new ActionsClassException("Item actions class is not set for " . static::class);
            }
            if (!is_subclass_of($class, DbActions::class)) {
                throw new ActionsClassException("Item actions class [{$class}] must extend " . DbActions::class);
            }
            $this->_actions = new $class($this);
        }

        return $this->_actions;
    }

    /**
     * Public clone - prevent cloning.
     *
     * Cloning DbItem would create duplicate references to the same Object.
     *
     * @throws CloneException Always (cloning not allowed)
     */
    public function __clone(): void
    {
        throw new CloneException('DbItem cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization.
     *
     * Unserializing DbItem would create invalid references to Object instances.
     *
     * @throws UnserializeException Always (unserialization not allowed)
     */
    public function __wakeup(): void
    {
        throw new UnserializeException('DbItem cannot be unserialized');
    }

    /**
     * Property getter.
     * Child classes should override and call parent::__get() in default case.
     *
     * @param string $name Property name
     * @return mixed
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     */
    public function __get(string $name)
    {
        return match ($name) {
            self::actions => $this->getActions(),
            self::object => $this->_object,
            default => throw new PropertyNotFoundException("Property [{$name}] does not exist on " . static::class),
        };
    }

    /**
     * Property setter - prevents modification (read-only)
     *
     * @param string $name Property name
     * @param mixed $value Property value
     * @return never
     * @throws ReadOnlyException Always throws (read-only)
     */
    final public function __set(string $name, mixed $value): never
    {
        $className = static::class;
        throw new ReadOnlyException("Cannot set property [{$name}] on {$className}: DbItem is read-only");
    }

    /**
     * Convert to array.
     * Child classes override to add/modify fields (e.g. User adds presence for frontend).
     *
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend When true, exclude fields that must not be sent to frontend (e.g. sessionToken). ID is always included when true.
     * @return array<string, mixed>
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
    {
        $result = $this->_object->toArray();
        $includeId = $withId || $toFrontend;

        if (!$includeId) {
            foreach ($this->_object->getPrimaryKeyArrayKeys() as $key) {
                if (array_key_exists($key, $result)) {
                    unset($result[$key]);
                }
            }
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Hilos\Database\Item;

use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Hilos\Database\Exception\Item\CloneException;
use Hilos\Hilos\Database\Exception\Item\PropertyNotFoundException;
use Hilos\Hilos\Database\Exception\Item\ReadOnlyException;
use Hilos\Hilos\Database\Exception\Item\UnserializeException;
use Hilos\Database\Object\Item\Object_;

/**
 * Base class for Db items (read-only wrappers around Object instances).
 * High-level access with lazy loading support for relationships.
 * DbItem stores only reference to Object for memory efficiency.
 *
 * @template TObject of Object_
 * @property-read mixed $id Primary key value
 * @property-read TObject $_object Reference to Object instance
 */
abstract class DbItem
{
    /**
     * Reference to Object instance
     * Object is stored in ObjectCollection in storage
     *
     * @var TObject
     */
    protected Object_ $_object;

    /**
     * Public constructor - creates DbItem from Object instance
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
     * Public clone - prevent cloning
     * Cloning DbItem would create duplicate references to the same Object.
     *
     * @throws CloneException
     */
    public function __clone(): void
    {
        throw new CloneException('DbItem cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization
     * Unserializing DbItem would create invalid references to Object instances.
     *
     * @throws UnserializeException
     */
    public function __wakeup(): void
    {
        throw new UnserializeException('DbItem cannot be unserialized');
    }

    /**
     * Property getter - throws error for undeclared properties
     * Child classes should override and call parent::__get() in default case.
     *
     * @param string $name Property name
     * @return never
     * @throws PropertyNotFoundException Always throws for undeclared properties
     */
    public function __get(string $name)
    {
        $className = static::class;
        throw new PropertyNotFoundException("Property [{$name}] does not exist on {$className}");
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
     * Convert to array
     * Must be implemented by child classes
     *
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend When true, exclude fields that must not be sent to frontend (e.g. sessionToken)
     * @return array
     */
    abstract public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array;
}

<?php

namespace Hilos\Database\Idea;

use Hilos\Database\Object\Object_;
use RuntimeException;

/**
 * Base class for individual Idea items
 *
 * Idea items are read-only wrappers around Object instances
 * They provide high-level access to data with lazy loading support for relationships
 *
 * IdeaItem stores only reference to Object for memory efficiency.
 * Object instances are stored in ObjectCollection in Idea.
 *
 * @template TObject of Object_
 * @property-read mixed $id Primary key value
 * @property-read TObject $_object Reference to Object instance
 */
abstract class IdeaItem
{
    /**
     * Reference to Object instance
     * Object is stored in ObjectCollection in IdeaStorage
     *
     * @var TObject
     */
    protected Object_ $_object;

    /**
     * Public constructor - creates IdeaItem from Object instance
     *
     * @param Object_ $object Object instance (reference)
     */
    public function __construct(Object_ &$object)
    {
        $this->_object = &$object;
    }

    /**
     * Public clone - prevent cloning
     *
     * Magic methods in PHP must be public to be called.
     * Cloning IdeaItem would create duplicate references to the same Object,
     * which could lead to inconsistent state. If you need multiple IdeaItem
     * instances for the same Object, create them separately.
     */
    public function __clone(): void
    {
        throw new RuntimeException('IdeaItem cannot be cloned');
    }

    /**
     * Public wakeup - prevent unserialization
     *
     * Magic methods in PHP must be public to be called.
     * Unserializing IdeaItem would create invalid references to Object instances
     * that may no longer exist or be in a different state. Object references cannot
     * be safely serialized/unserialized.
     */
    public function __wakeup(): void
    {
        throw new RuntimeException('IdeaItem cannot be unserialized');
    }

    /**
     * Property getter - throws error for undeclared properties
     *
     * Child classes should override this method and call parent::__get() in default case
     * for properties that are not handled by the child class.
     *
     * @param string $name Property name
     * @return never
     * @throws RuntimeException Always throws exception for undeclared properties
     */
    public function __get(string $name)
    {
        $className = static::class;
        throw new RuntimeException("Property [{$name}] does not exist on {$className}");
    }

    /**
     * Property setter - prevents modification (read-only)
     *
     * IdeaItem is read-only wrapper, properties cannot be modified.
     *
     * @param string $name Property name
     * @param mixed $value Property value
     * @return never
     * @throws RuntimeException Always throws exception (read-only)
     */
    final public function __set(string $name, mixed $value): never
    {
        $className = static::class;
        throw new RuntimeException("Cannot set property [{$name}] on {$className}: IdeaItem is read-only");
    }

    /**
     * Convert to array
     * Must be implemented by child classes
     *
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @return array
     */
    abstract public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array;
}

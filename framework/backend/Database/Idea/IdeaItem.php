<?php

namespace Hilos\Database\Idea;

use Hilos\Database\Object\Object_;

/**
 * Base class for individual Idea items
 * 
 * Idea items are read-only wrappers around Object instances
 * They provide high-level access to data with lazy loading support for relationships
 * 
 * IdeaItem stores only reference to Object for memory efficiency.
 * Object instances are stored in ObjectCollection in IdeaStorage.
 * 
 * @property-read mixed $id Primary key value
 */
abstract class IdeaItem
{
    /**
     * Reference to Object instance
     * Object is stored in ObjectCollection in IdeaStorage
     * 
     * @var Object_
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
     * Get Object instance
     * 
     * @return Object_ Object instance
     */
    protected function getObject(): Object_
    {
        return $this->_object;
    }

    /**
     * Get IdeaStorage instance
     * Provides access to Object collections for creating related Idea collections
     * 
     * This method accesses IdeaStorage through the Idea class static property.
     * Child classes should override this if they use a different Idea class namespace.
     * 
     * @return IdeaStorage|null
     */
    protected function getStorage(): ?IdeaStorage
    {
        // Access IdeaStorage through Idea static property
        // Note: This assumes Idea class is in the same namespace or accessible
        // Child classes in application code may need to override this method
        // to access their application-specific Idea class
        return Idea::$storage ?? null;
    }

    /**
     * Private clone - prevent cloning
     * 
     * Cloning IdeaItem would create duplicate references to the same Object,
     * which could lead to inconsistent state. If you need multiple IdeaItem
     * instances for the same Object, create them separately.
     */
    private function __clone()
    {
    }

    /**
     * Private wakeup - prevent unserialization
     * 
     * Unserializing IdeaItem would create invalid references to Object instances
     * that may no longer exist or be in a different state. Object references cannot
     * be safely serialized/unserialized.
     */
    public function __wakeup(): void
    {
        throw new \RuntimeException('IdeaItem cannot be unserialized');
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


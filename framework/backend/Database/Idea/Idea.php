<?php

namespace Hilos\Database\Idea;

use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use RuntimeException;

/**
 * Idea - Static access point for read-only data access
 *
 * Provides global access to Idea collections through Object collections
 * All data access is read-only through Idea objects
 *
 * Usage:
 *   Idea::init(); // Initialize with Object collections (mandatory)
 *   $user = Idea::$idea->users[123]; // Get User idea
 *   $users = Idea::$idea->users; // Get Users collection
 */
class Idea
{
    /** @var static|null Singleton instance (may be instance of child class) */
    public static ?self $idea = null;

    /**
     * Object collections (references to Objects instances)
     * These are set by setRepresent() method
     * Protected to allow access from IdeaCollection via getObjectCollection()
     *
     * @var array<string, Objects>
     */
    protected array $_objectCollections = [];

    /**
     * Idea collections (wrappers around Object collections)
     * These are created automatically from Object collections
     *
     * @var array<string, IdeaCollection>
     */
    protected array $_ideaCollections = [];

    /**
     * Private constructor - use init() instead
     */
    protected function __construct()
    {
    }

    /**
     * Public clone - prevent cloning
     *
     * Magic methods in PHP must be public to be called.
     * Idea is a singleton and should not be cloned.
     */
    public function __clone(): void
    {
        throw new RuntimeException('Idea cannot be cloned');
    }

    /**
     * Initialize Idea with Object collections
     *
     * Object collections initialization is mandatory and must be implemented in child classes.
     * Child classes should override this method to create and register Object collections.
     */
    public static function init(): void
    {
        if (self::$idea === null) {
            self::$idea = new self();
        }

        // Object collections must be initialized in child class
        // Child classes should override this method to create Object collections
        // and register them via setRepresent() method
    }

    /**
     * Set representation for Object collection
     * Creates corresponding Idea collection automatically
     * Object collection must be already stored in _objectCollections[$name]
     *
     * @param string $name Collection name (e.g., 'users')
     * @param string $ideaCollectionClass Idea collection class name
     * @param string|null $actionsClass Actions class name (optional)
     * @throws DatabaseException If Object collection not found in _objectCollections
     */
    public function setRepresent(string $name, string $ideaCollectionClass, ?string $actionsClass = null): void
    {
        // Get Object collection from _objectCollections
        if (!isset($this->_objectCollections[$name])) {
            throw new DatabaseException("Object collection [{$name}] not found in _objectCollections. Create it before calling setRepresent().");
        }
        
        $objectCollection = $this->_objectCollections[$name];
        
        // Create Idea collection
        if (is_subclass_of($ideaCollectionClass, IdeaCollection::class)) {
            $ideaCollection = $ideaCollectionClass::init();
            // Pass Object collection to IdeaCollection
            $ideaCollection->setObjectCollection($objectCollection);
            // Set Actions class if provided
            if ($actionsClass !== null) {
                $ideaCollection->setActionsClass($actionsClass);
            }
            $this->_ideaCollections[$name] = $ideaCollection;
        }
    }

    /**
     * Get Object collection by name
     * Used internally by IdeaCollection to access Object collections
     *
     * @param string $name Collection name
     * @return Objects|null Object collection or null if not found
     */
    public function getObjectCollection(string $name): ?Objects
    {
        return $this->_objectCollections[$name] ?? null;
    }

    /**
     * Get Idea collection by name
     * For LAZY_STRATEGY_NONE: Loads all data from database on first access (read or write)
     *
     * @param string $name Collection name
     * @return IdeaCollection
     * @throws DatabaseException
     */
    public function __get(string $name)
    {
        if (!isset($this->_ideaCollections[$name])) {
            throw new DatabaseException("Idea collection [{$name}] does not exist");
        }

        // Check if ObjectCollection has LAZY_STRATEGY_NONE and needs loading
        $objectCollection = $this->_objectCollections[$name] ?? null;
        if ($objectCollection !== null && 
            $objectCollection->getLazyStrategy() === Objects::LAZY_STRATEGY_NONE &&
            !$objectCollection->isAllLoaded()) {
            // Load all data from database on first access
            $objectCollection->loadAllFromDB();
        }

        return $this->_ideaCollections[$name];
    }

    /**
     * Convert all collections to array
     *
     * @return array<string, array>
     */
    public function toArray(): array
    {
      return array_map(function ($collection) {
        return $collection->toArray();
      }, $this->_ideaCollections);
    }
}

<?php

namespace Hilos\Database\Idea;

use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use RuntimeException;

/**
 * Idea - Static access point for read-only data access
 *
 * Provides global access to Idea collections through IdeaStorage
 * All data access is read-only through Idea objects
 *
 * Usage:
 *   Idea::init(true); // Initialize with IdeaStorage
 *   $user = Idea::$idea->users[123]; // Get User idea
 *   $users = Idea::$idea->users; // Get Users collection
 */
class Idea
{
    /** @var static|null Singleton instance (may be instance of child class) */
    public static ?self $idea = null;

    /** @var IdeaStorage|null Storage instance */
    public static ?IdeaStorage $storage = null;

    /**
     * Object collections (references to Objects instances)
     * These are set by setRepresent*() methods
     *
     * @var array<string, Objects>
     */
    private array $_objectCollections = [];

    /**
     * Idea collections (wrappers around Object collections)
     * These are created automatically from Object collections
     *
     * @var array<string, IdeaCollection>
     */
    private array $_ideaCollections = [];

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
     * Initialize Idea with storage
     *
     * @param bool $withStorage If true, initialize IdeaStorage
     * @throws DatabaseException
     */
    public static function init(bool $withStorage = false): void
    {
        if (self::$idea === null) {
            self::$idea = new self();
        }

        if ($withStorage && self::$storage === null) {
            // IdeaStorage should be created in application code
            // This is a placeholder - child classes should override
            throw new DatabaseException("IdeaStorage must be initialized in application code");
        }
    }

    /**
     * Set IdeaStorage instance
     *
     * @param IdeaStorage $storage
     */
    public static function setStorage(IdeaStorage $storage): void
    {
        self::$storage = $storage;
    }

    /**
     * Set representation for Object collection
     * Creates corresponding Idea collection automatically
     *
     * @param string $name Collection name (e.g., 'users')
     * @param Objects $objectCollection Object collection instance
     * @param string $ideaCollectionClass Idea collection class name
     */
    public function setRepresent(string $name, Objects &$objectCollection, string $ideaCollectionClass): void
    {
        $this->_objectCollections[$name] = &$objectCollection;

        // Create Idea collection (without passing ObjectCollection)
        // IdeaCollection will get ObjectCollection from IdeaStorage via getObjectCollection()
        if (is_subclass_of($ideaCollectionClass, IdeaCollection::class)) {
            $this->_ideaCollections[$name] = $ideaCollectionClass::init();
        }
    }

    /**
     * Get Idea collection by name
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

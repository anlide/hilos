<?php

namespace Hilos\Database\Idea;

use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Collection\IdeaCollectionNotFoundException;
use Hilos\Exception\Idea\Entity\IdeaEntityClassNotFoundException;
use Hilos\Exception\Idea\Entity\IdeaEntityMappingNotFoundException;
use Hilos\Exception\Idea\Entity\IdeaEntityTableConstantNotFoundException;
use Hilos\Exception\Idea\Other\IdeaCloneException;
use Hilos\Exception\Idea\Other\IdeaObjectCollectionNotFoundException;
use Hilos\Exception\Idea\Other\IdeaUnknownLazyStrategyException;
use Hilos\Runtime\Idea\IdeaRt;

/**
 * Idea - Static access point for application data
 *
 * Provides global access to two data layers:
 *   - $db: Database layer (Idea collections, Object collections, Entity layer)
 *   - $rt: Runtime layer for transient application data (connections, sessions, state)
 *
 * Usage:
 *   Idea::init(); // Initialize (mandatory)
 *   $user = Idea::$db->users[123]; // Get User from database layer
 *   $conn = Idea::$rt->connections[$acceptKey]; // Get from runtime layer
 */
abstract class Idea
{
    /** @var ?static Database layer singleton (Idea/Object/Entity collections) */
    public static ?self $db = null;

    /** @var ?IdeaRt Runtime layer singleton for transient application data */
    public static ?IdeaRt $rt = null;

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
     * @throws IdeaCloneException
     */
    public function __clone(): void
    {
        throw new IdeaCloneException('Idea cannot be cloned');
    }

    /**
     * Initialize Idea with Object collections and Runtime
     *
     * Object collections initialization is mandatory and must be implemented in child classes.
     * Child classes should override this method to create and register Object collections.
     * Runtime is optional - override createRuntime() to provide application-specific runtime.
     */
    public static function init(): void
    {
        if (self::$db === null) {
            self::$db = new static();
        }

        if (self::$rt === null) {
            self::$rt = static::createRuntime();
        }

        // Object collections must be initialized in child class
        // Child classes should override this method to create Object collections
        // and register them via setRepresent() method
    }

    /**
     * Create runtime instance
     *
     * Override in child class to provide application-specific runtime.
     * Return null if runtime is not needed.
     *
     * @return ?IdeaRt Runtime instance or null
     */
    protected static function createRuntime(): ?IdeaRt
    {
        return null;
    }

    /**
     * Set representation for Object collection
     * Creates corresponding Idea collection automatically
     * Object collection must be already stored in _objectCollections[$name]
     *
     * @param string $name Collection name (e.g., 'users')
     * @param string $ideaCollectionClass Idea collection class name
     * @param ?string $actionsClass Actions class name (optional)
     * @throws IdeaObjectCollectionNotFoundException If Object collection not found in _objectCollections
     */
    public function setRepresent(string $name, string $ideaCollectionClass, ?string $actionsClass = null): void
    {
        // Get Object collection from _objectCollections
        if (!isset($this->_objectCollections[$name])) {
            throw new IdeaObjectCollectionNotFoundException("Object collection [{$name}] not found in _objectCollections. Create it before calling setRepresent().");
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
     * @return ?Objects Object collection or null if not found
     */
    public function getObjectCollection(string $name): ?Objects
    {
        return $this->_objectCollections[$name] ?? null;
    }

    /**
     * Get Idea collection by name
     * Handles lazy loading based on ObjectCollection strategy
     *
     * @param string $name Collection name
     * @return IdeaCollection
     * @throws IdeaCollectionNotFoundException
     * @throws IdeaUnknownLazyStrategyException
     * @throws DatabaseException
     */
    public function __get(string $name)
    {
        // Early exit if collection doesn't exist
        if (!isset($this->_ideaCollections[$name])) {
            throw new IdeaCollectionNotFoundException("Idea collection [{$name}] does not exist");
        }

        switch ($this->_objectCollections[$name]->getLazyStrategy()) {
            case Objects::LAZY_STRATEGY_NONE:
                // Load all data from database on first access if not loaded
                if (!$this->_objectCollections[$name]->isAllLoaded()) {
                    $this->_objectCollections[$name]->loadAllFromDB();
                }
                break;

            case Objects::LAZY_STRATEGY_KEY:
                // For KEY strategy, no action needed on collection access
                // Objects will be loaded lazily when accessed by key via offsetGet()
                // This allows efficient memory usage - only requested objects are loaded
                break;

            case Objects::LAZY_STRATEGY_BATCH:
                // TODO: Implement batch lazy loading strategy
                break;

            case Objects::LAZY_STRATEGY_FULL_ON_ACCESS:
                // TODO: Implement full load on access strategy
                break;

            default:
                // Unknown strategy - no action needed
                throw new IdeaUnknownLazyStrategyException("Unknown lazy loading strategy for collection [{$name}]");
        }

        return $this->_ideaCollections[$name];
    }

    /**
     * Get entity mapping for collections
     * Maps collection names to Entity class names
     * Must be implemented in child classes
     *
     * @return array<string, string> Mapping of collection name => Entity class name
     */
    abstract protected static function getEntityMapping(): array;

    /**
     * Get table name for collection
     * Uses entity mapping to get table name from Entity class
     *
     * @param string $collectionName Collection name
     * @return string Table name
     * @throws IdeaEntityMappingNotFoundException If collection not found in mapping
     * @throws IdeaEntityClassNotFoundException If entity class does not exist
     * @throws IdeaEntityTableConstantNotFoundException If entity class does not have _table constant
     */
    public static function getTableName(string $collectionName): string
    {
        $entityMapping = static::getEntityMapping();
        $entityClass = $entityMapping[$collectionName] ?? null;

        if ($entityClass === null) {
            throw new IdeaEntityMappingNotFoundException("No entity mapping for collection: {$collectionName}");
        }

        if (!class_exists($entityClass)) {
            throw new IdeaEntityClassNotFoundException("Entity class does not exist: {$entityClass}");
        }

        // Get _table constant from Entity class
        $reflection = new \ReflectionClass($entityClass);
        $tableConstant = $reflection->getConstant('_table');

        if ($tableConstant === false) {
            throw new IdeaEntityTableConstantNotFoundException("Entity class [{$entityClass}] does not have _table constant");
        }

        return $tableConstant;
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

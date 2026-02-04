<?php

namespace Hilos\Runtime\Idea;

use Hilos\Exception\Runtime\Rt\IdeaRtCloneException;
use Hilos\Exception\Runtime\Rt\IdeaRtCollectionNotFoundException;
use Hilos\Exception\Runtime\Rt\IdeaRtStateCollectionNotFoundException;
use Hilos\Runtime\State\RtStates;

/**
 * Base class for runtime data access
 *
 * IdeaRt provides global access to runtime state collections (analogous to Idea for database).
 * It manages state collections (RtStates) and their Idea wrappers (IdeaRtCollection).
 *
 * Runtime data is transient - it lives only in memory for the process lifetime.
 * No database synchronization or persistence.
 *
 * Usage:
 *   Idea::$rt->connections[acceptKey]; // Get connection by accept key
 *   Idea::$rt->connections->actions->register(acceptKey, userId); // Register connection
 *
 * Child classes must:
 *   1. Override init() to create and register state collections
 *   2. Use setRepresent() to register collections
 */
abstract class IdeaRt
{
    /**
     * State collections storage (RtStates instances)
     * These are the single source of truth for runtime data
     *
     * @var array<string, RtStates>
     */
    protected array $_stateCollections = [];

    /**
     * Idea runtime collections (IdeaRtCollection wrappers)
     * Lightweight wrappers providing read-only access and actions
     *
     * @var array<string, IdeaRtCollection>
     */
    protected array $_rtCollections = [];

    /**
     * Protected constructor - use static factory methods
     */
    protected function __construct()
    {
    }

    /**
     * Public clone - prevent cloning
     *
     * @throws IdeaRtCloneException
     */
    public function __clone(): void
    {
        throw new IdeaRtCloneException('IdeaRt cannot be cloned');
    }

    /**
     * Initialize runtime instance
     *
     * Override in child classes to create and register state collections.
     *
     * @return static
     */
    public static function init(): static
    {
        return new static();
    }

    /**
     * Set representation for state collection
     *
     * Creates corresponding IdeaRtCollection wrapper automatically.
     * State collection must be already stored in _stateCollections[$name].
     *
     * @param string $name Collection name (e.g., 'connections')
     * @param string $rtCollectionClass IdeaRtCollection class name
     * @param ?string $actionsClass IdeaRtActions class name (optional)
     * @throws IdeaRtStateCollectionNotFoundException If state collection not found
     */
    public function setRepresent(string $name, string $rtCollectionClass, ?string $actionsClass = null): void
    {
        // Get state collection from _stateCollections
        if (!isset($this->_stateCollections[$name])) {
            throw new IdeaRtStateCollectionNotFoundException(
                "State collection [{$name}] not found in _stateCollections. Create it before calling setRepresent()."
            );
        }

        $stateCollection = $this->_stateCollections[$name];

        // Create IdeaRtCollection wrapper
        if (is_subclass_of($rtCollectionClass, IdeaRtCollection::class)) {
            $rtCollection = $rtCollectionClass::init();
            // Pass state collection to IdeaRtCollection
            $rtCollection->setStateCollection($stateCollection);
            // Set collection name for truth source registry
            $rtCollection->setCollectionName($name);
            // Set Actions class if provided
            if ($actionsClass !== null) {
                $rtCollection->setActionsClass($actionsClass);
            }
            $this->_rtCollections[$name] = $rtCollection;
        }
    }

    /**
     * Get state collection by name
     *
     * Used internally by IdeaRtCollection to access state storage.
     *
     * @param string $name Collection name
     * @return ?RtStates State collection or null if not found
     */
    public function getStateCollection(string $name): ?RtStates
    {
        return $this->_stateCollections[$name] ?? null;
    }

    /**
     * Get IdeaRtCollection by name
     *
     * Provides access to runtime collections through magic property access.
     *
     * @param string $name Collection name
     * @return IdeaRtCollection
     * @throws IdeaRtCollectionNotFoundException If collection not found
     */
    public function __get(string $name): IdeaRtCollection
    {
        if (!isset($this->_rtCollections[$name])) {
            throw new IdeaRtCollectionNotFoundException("Runtime collection [{$name}] does not exist");
        }

        return $this->_rtCollections[$name];
    }

    /**
     * Convert all collections to array
     *
     * @return array<string, array>
     */
    public function toArray(): array
    {
        return array_map(
            fn(IdeaRtCollection $collection) => $collection->toArray(),
            $this->_rtCollections
        );
    }

    /**
     * Debug info
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Context;

use Hilos\Runtime\Exception\Rt\RtCloneException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\View\Actions\RtActions;
use Hilos\Runtime\View\Collection\RtCollection;

/**
 * RtContext - runtime context (transient application data).
 *
 * Manages state collections and their runtime view wrappers.
 * Runtime data is transient - it lives only in memory for the process lifetime.
 */
abstract class RtContext
{
    /** @var array<string, RtStates> Map of collection name to state collection */
    protected array $_stateCollections = [];

    /** @var array<string, RtCollection> Map of collection name to runtime view collection */
    protected array $_rtCollections = [];

    /**
     * Protected constructor. Child classes use init() to create instance.
     */
    protected function __construct()
    {
    }

    /**
     * Prevents cloning (runtime context holds references).
     *
     * @throws RtCloneException Always, cloning not allowed
     */
    public function __clone(): void
    {
        throw new RtCloneException('Runtime context cannot be cloned');
    }

    /**
     * Creates new runtime context instance.
     *
     * @return static New RtContext instance
     */
    public static function init(): static
    {
        return new static();
    }

    /**
     * Set representation for state collection.
     *
     * @param string $name Collection name
     * @param class-string<RtCollection> $rtCollectionClass RtCollection class name
     * @param ?class-string<RtActions> $actionsClass RtActions class name (optional)
     * @throws StateCollectionNotFoundException When state collection not found
     */
    public function setRepresent(string $name, string $rtCollectionClass, ?string $actionsClass = null): void
    {
        if (!isset($this->_stateCollections[$name])) {
            throw new StateCollectionNotFoundException(
                "State collection [{$name}] not found in _stateCollections. Create it before calling setRepresent()."
            );
        }

        $stateCollection = $this->_stateCollections[$name];

        if (is_subclass_of($rtCollectionClass, RtCollection::class)) {
            $rtCollection = $rtCollectionClass::init();
            $rtCollection->setStateCollection($stateCollection);
            $rtCollection->setCollectionName($name);
            if ($actionsClass !== null) {
                $rtCollection->setActionsClass($actionsClass);
            }
            $this->_rtCollections[$name] = $rtCollection;
        }
    }

    /**
     * Get state collection by name.
     *
     * @param string $name Collection name
     * @return ?RtStates State collection or null if not found
     */
    public function getStateCollection(string $name): ?RtStates
    {
        return $this->_stateCollections[$name] ?? null;
    }

    /**
     * Get runtime collection by name (magic getter for $rt->collectionName).
     *
     * @param string $name Collection name
     * @return RtCollection Runtime collection instance
     * @throws RtCollectionNotFoundException When collection does not exist
     */
    public function __get(string $name): RtCollection
    {
        if (!isset($this->_rtCollections[$name])) {
            throw new RtCollectionNotFoundException("Runtime collection [{$name}] does not exist");
        }
        return $this->_rtCollections[$name];
    }

    /**
     * Convert all collections to array.
     *
     * @return array<string, array<string, array<string, mixed>>> Collection name => items array
     */
    public function toArray(): array
    {
        return array_map(
            fn(RtCollection $collection) => $collection->toArray(),
            $this->_rtCollections
        );
    }

    /**
     * Debug info for var_dump (returns all collections as array).
     *
     * @return array<string, array<string, array<string, mixed>>> Collection name => items array
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

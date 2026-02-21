<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Context;

use Hilos\Runtime\Exception\Rt\RtCloneException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\View\Collection\RtCollection;

/**
 * RtContext - runtime context (transient application data).
 *
 * Manages state collections and their runtime view wrappers.
 * Runtime data is transient - it lives only in memory for the process lifetime.
 */
abstract class RtContext
{
    /** @var array<string, RtStates> */
    protected array $_stateCollections = [];

    /** @var array<string, RtCollection> */
    protected array $_rtCollections = [];

    protected function __construct()
    {
    }

    /** @throws RtCloneException */
    public function __clone(): void
    {
        throw new RtCloneException('Runtime context cannot be cloned');
    }

    public static function init(): static
    {
        return new static();
    }

    /**
     * @param string $rtCollectionClass RtCollection class name
     * @param ?string $actionsClass RtActions class name (optional)
     * @throws StateCollectionNotFoundException
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

    public function getStateCollection(string $name): ?RtStates
    {
        return $this->_stateCollections[$name] ?? null;
    }

    /** @throws RtCollectionNotFoundException */
    public function __get(string $name): RtCollection
    {
        if (!isset($this->_rtCollections[$name])) {
            throw new RtCollectionNotFoundException("Runtime collection [{$name}] does not exist");
        }
        return $this->_rtCollections[$name];
    }

    public function toArray(): array
    {
        return array_map(
            fn(RtCollection $collection) => $collection->toArray(),
            $this->_rtCollections
        );
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Hilos\Runtime\View\Collection;

use ArrayAccess;
use Countable;
use Hilos\Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Hilos\Runtime\Exception\Collection\RtCollectionCloneException;
use Hilos\Hilos\Runtime\Exception\Collection\RtCollectionDirectSetException;
use Hilos\Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Hilos\Runtime\Exception\Collection\RtCollectionUnserializeException;
use Hilos\Hilos\Runtime\State\Item\RtState;
use Hilos\Hilos\Runtime\State\Collection\RtStates;
use Hilos\Hilos\Runtime\View\Actions\RtActions;
use Hilos\Hilos\Runtime\View\Item\RtItem;
use Iterator;

/**
 * RtCollection - collection of RtItem instances (view layer).
 *
 * Read-only wrapper around runtime state collection.
 *
 * @template T of RtItem
 * @implements ArrayAccess<string, T>
 * @implements Iterator<string, T>
 */
abstract class RtCollection implements ArrayAccess, Countable, Iterator
{
    public const string actions = 'actions';

    private int $position = 0;

    /** @var array<string, T> */
    private array $items = [];

    private ?RtStates $_stateCollection = null;
    private ?string $_collectionName = null;
    private ?string $_actionsClass = null;
    private ?RtActions $_actions = null;

    protected function __construct()
    {
    }

    public static function init(): static
    {
        return new static();
    }

    /** @throws RtCollectionCloneException */
    public function __clone(): void
    {
        throw new RtCollectionCloneException('RtCollection cannot be cloned');
    }

    /** @throws RtCollectionUnserializeException */
    public function __wakeup(): void
    {
        throw new RtCollectionUnserializeException('RtCollection cannot be unserialized');
    }

    public function setStateCollection(RtStates &$stateCollection): void
    {
        $this->_stateCollection = &$stateCollection;
    }

    public function setCollectionName(string $name): void
    {
        $this->_collectionName = $name;
    }

    public function getCollectionName(): ?string
    {
        return $this->_collectionName;
    }

    public function setActionsClass(?string $actionsClass): void
    {
        $this->_actionsClass = $actionsClass;
    }

    /** @throws RtCollectionActionsClassException */
    protected function getActions(): RtActions
    {
        if ($this->_actions === null) {
            if ($this->_actionsClass === null) {
                throw new RtCollectionActionsClassException(
                    "Actions class is not set for " . static::class
                );
            }

            $class = $this->_actionsClass;
            if (!is_subclass_of($class, RtActions::class)) {
                throw new RtCollectionActionsClassException(
                    "Actions class [{$class}] must extend RtActions"
                );
            }

            $this->_actions = new $class($this);

            $this->_actions->setCreateRtItemCallback(function (RtState &$state): RtItem {
                return $this->createRtItem($state);
            });

            $this->_actions->setClearCacheCallback(function (): void {
                $this->clearCache();
            });
        }

        return $this->_actions;
    }

    /** @throws RtActionsStateCollectionNullException */
    public function getStateCollection(): RtStates
    {
        if ($this->_stateCollection === null) {
            throw new RtActionsStateCollectionNullException(
                'State collection is null. Call setStateCollection() before using the collection.'
            );
        }
        return $this->_stateCollection;
    }

    /** @param RtState $state RtState instance (reference) */
    abstract protected function createRtItem(RtState &$state): RtItem;

    protected function getRtItemForKey(string $key): ?RtItem
    {
        if (isset($this->items[$key])) {
            return $this->items[$key];
        }

        $stateCollection = $this->getStateCollection();
        $state = $stateCollection[$key] ?? null;
        if ($state === null) {
            return null;
        }

        $item = $this->createRtItem($state);
        $this->items[$key] = $item;

        return $item;
    }

    public function toArray(bool $idAsIndex = true): array
    {
        $result = [];
        $stateCollection = $this->getStateCollection();

        foreach ($stateCollection as $key => $state) {
            $item = $this->getRtItemForKey($key);
            if ($item !== null) {
                $data = $item->toArray();
                if ($idAsIndex) {
                    $result[$key] = $data;
                } else {
                    $result[] = $data;
                }
            }
        }

        return $result;
    }

    public function first(): ?RtItem
    {
        $stateCollection = $this->getStateCollection();
        $firstState = $stateCollection->first();
        if ($firstState === null) {
            return null;
        }
        return $this->getRtItemForKey($firstState->getId());
    }

    public function last(): ?RtItem
    {
        $stateCollection = $this->getStateCollection();
        $lastState = $stateCollection->last();
        if ($lastState === null) {
            return null;
        }
        return $this->getRtItemForKey($lastState->getId());
    }

    public function clearCache(): void
    {
        $this->items = [];
        $this->position = 0;
    }

    /** @throws RtCollectionPropertyNotFoundException */
    public function __get(string $name): mixed
    {
        if ($name === self::actions) {
            return $this->getActions();
        }
        throw new RtCollectionPropertyNotFoundException(
            "Property [{$name}] does not exist on " . static::class
        );
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        $stateCollection = $this->getStateCollection();
        return isset($stateCollection[$offset]);
    }

    public function offsetGet(mixed $offset): ?RtItem
    {
        return $this->getRtItemForKey((string)$offset);
    }

    /** @throws RtCollectionDirectSetException */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new RtCollectionDirectSetException(
            "Cannot directly set items in collection. Use actions for modifications."
        );
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[(string)$offset]);
        $this->getStateCollection()->remove((string)$offset);
    }

    public function count(): int
    {
        return $this->getStateCollection()->count();
    }

    public function current(): ?RtItem
    {
        $keys = array_keys($this->items);
        if (!isset($keys[$this->position])) {
            return null;
        }
        return $this->items[$keys[$this->position]];
    }

    public function key(): ?string
    {
        $keys = array_keys($this->items);
        return $keys[$this->position] ?? null;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function rewind(): void
    {
        $this->position = 0;
        $stateCollection = $this->getStateCollection();
        $this->items = [];
        foreach ($stateCollection as $key => $state) {
            $this->items[$key] = $this->createRtItem($state);
            unset($state);
        }
        $stateCollection->rewind();
    }

    public function valid(): bool
    {
        $keys = array_keys($this->items);
        return $this->position < count($keys);
    }
}

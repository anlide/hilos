<?php

namespace Hilos\Hilos\Runtime\Base;

use ArrayAccess;
use Countable;
use Hilos\Exception\Runtime\Actions\IdeaRtActionsStateCollectionNullException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionActionsClassException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionCloneException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionDirectSetException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionPropertyNotFoundException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionUnserializeException;
use Hilos\Hilos\Runtime\State\Item\RtState;
use Hilos\Hilos\Runtime\State\Collection\RtStates;
use Iterator;

/**
 * Base class for runtime collections (read-only wrapper around RtStates).
 *
 * @template T of RtItemBase
 * @implements ArrayAccess<string, T>
 * @implements Iterator<string, T>
 */
abstract class RtCollectionBase implements ArrayAccess, Countable, Iterator
{
    public const string actions = 'actions';

    private int $position = 0;
    /** @var array<string, T> */
    private array $items = [];
    private ?RtStates $_stateCollection = null;
    private ?string $_collectionName = null;
    private ?string $_actionsClass = null;
    private ?RtActionsBase $_actions = null;

    protected function __construct()
    {
    }

    public static function init(): static
    {
        return new static();
    }

    /** @throws IdeaRtCollectionCloneException */
    public function __clone(): void
    {
        throw new IdeaRtCollectionCloneException('RtCollection cannot be cloned');
    }

    /** @throws IdeaRtCollectionUnserializeException */
    public function __wakeup(): void
    {
        throw new IdeaRtCollectionUnserializeException('RtCollection cannot be unserialized');
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

    /** @throws IdeaRtCollectionActionsClassException */
    protected function getActions(): RtActionsBase
    {
        if ($this->_actions === null) {
            if ($this->_actionsClass === null) {
                throw new IdeaRtCollectionActionsClassException(
                    "Actions class is not set for " . static::class
                );
            }

            $class = $this->_actionsClass;
            if (!is_subclass_of($class, RtActionsBase::class)) {
                throw new IdeaRtCollectionActionsClassException(
                    "Actions class [{$class}] must extend RtActionsBase"
                );
            }

            $this->_actions = new $class($this);

            $this->_actions->setCreateRtItemCallback(function (RtState &$state): RtItemBase {
                return $this->createRtItem($state);
            });

            $this->_actions->setClearCacheCallback(function (): void {
                $this->clearCache();
            });
        }

        return $this->_actions;
    }

    /** @throws IdeaRtActionsStateCollectionNullException */
    public function getStateCollection(): RtStates
    {
        if ($this->_stateCollection === null) {
            throw new IdeaRtActionsStateCollectionNullException(
                'State collection is null. Call setStateCollection() before using the collection.'
            );
        }
        return $this->_stateCollection;
    }

    /** @param RtState $state RtState instance (reference) */
    abstract protected function createRtItem(RtState &$state): RtItemBase;

    protected function getRtItemForKey(string $key): ?RtItemBase
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

    public function first(): ?RtItemBase
    {
        $stateCollection = $this->getStateCollection();
        $firstState = $stateCollection->first();
        if ($firstState === null) {
            return null;
        }
        return $this->getRtItemForKey($firstState->getId());
    }

    public function last(): ?RtItemBase
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

    /** @throws IdeaRtCollectionPropertyNotFoundException */
    public function __get(string $name)
    {
        if ($name === self::actions) {
            return $this->getActions();
        }
        throw new IdeaRtCollectionPropertyNotFoundException(
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

    public function offsetGet(mixed $offset): ?RtItemBase
    {
        return $this->getRtItemForKey($offset);
    }

    /** @throws IdeaRtCollectionDirectSetException */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new IdeaRtCollectionDirectSetException(
            "Cannot directly set items in collection. Use actions for modifications."
        );
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
        $this->getStateCollection()->remove($offset);
    }

    public function count(): int
    {
        return $this->getStateCollection()->count();
    }

    public function current(): ?RtItemBase
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

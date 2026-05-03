<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemCloneException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\Exception\Item\RtItemReadOnlyException;
use Hilos\Runtime\Exception\Item\RtItemUnserializeException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Item\RtActions as RtItemActions;
use Hilos\Runtime\View\Collection\RtCollection;

/**
 * RtItem - read-only wrapper around runtime state.
 *
 * One-item write operations must go through this item's RtActions.
 *
 * @template TState of RtState
 * @property-read RtState $_state Reference to RtState instance
 */
abstract class RtItem
{
    public const string actions = 'actions';

    /** @var RtState reference to runtime state instance */
    protected RtState $_state;

    /** @var ?RtCollection Parent collection (set by RtCollection when item is created) */
    private ?RtCollection $_rtCollection = null;

    /** @var ?class-string<RtItemActions> Item actions class for lazy init */
    private ?string $_itemActionsClass = null;

    /** @var ?RtItemActions Cached item actions */
    private ?RtItemActions $_itemActions = null;

    /**
     * Creates Rt item wrapper around state reference.
     *
     * @param RtState $state State instance (passed by reference)
     */
    public function __construct(RtState &$state)
    {
        $this->_state = &$state;
    }

    /**
     * Returns the underlying state instance.
     *
     * @return RtState State reference
     */
    public function getState(): RtState
    {
        return $this->_state;
    }

    /**
     * Returns the state identifier.
     *
     * @return string State ID
     */
    public function getId(): string
    {
        return $this->_state->getId();
    }

    /**
     * Sets parent collection reference (called from RtCollection).
     */
    public function setCollection(RtCollection $collection): void
    {
        $this->_rtCollection = $collection;
    }

    /**
     * Returns parent collection or null if not attached.
     */
    public function getCollection(): ?RtCollection
    {
        return $this->_rtCollection;
    }

    /**
     * Sets item actions class for lazy initialization (called from RtCollection).
     *
     * @param ?class-string<RtItemActions> $itemActionsClass
     */
    public function setItemActionsClass(?string $itemActionsClass): void
    {
        $this->_itemActionsClass = $itemActionsClass;
    }

    /**
     * Item-level write operations (optional; class must be set on the collection).
     *
     * @return RtItemActions
     *
     * @throws RtItemActionsClassException If class not set or invalid
     */
    protected function getItemActions(): RtItemActions
    {
        if ($this->_itemActions === null) {
            $class = $this->_itemActionsClass
                ?? throw new RtItemActionsClassException(
                    'Item actions class is not set for ' . static::class
                );
            if (!is_subclass_of($class, RtItemActions::class)) {
                throw new RtItemActionsClassException(
                    "Item actions class [{$class}] must extend " . RtItemActions::class
                );
            }
            $this->_itemActions = new $class($this);
        }

        return $this->_itemActions;
    }

    /**
     * Forbids cloning (RtItem is read-only).
     *
     * @throws RtItemCloneException Always thrown
     */
    public function __clone(): void
    {
        throw new RtItemCloneException('RtItem cannot be cloned');
    }

    /**
     * Forbids unserialization (RtItem holds state reference).
     *
     * @throws RtItemUnserializeException Always thrown
     */
    public function __wakeup(): void
    {
        throw new RtItemUnserializeException('RtItem cannot be unserialized');
    }

    /**
     * Throws on property access (subclasses override for actual properties).
     *
     * @param string $name Property name
     * @return mixed Property value (never reached, throws instead)
     * @throws RtItemPropertyNotFoundException If the property does not exist
     */
    public function __get(string $name): mixed
    {
        $className = static::class;
        throw new RtItemPropertyNotFoundException("Property [{$name}] does not exist on {$className}");
    }

    /**
     * Forbids property assignment (RtItem is read-only).
     *
     * @param string $name Property name
     * @param mixed $value Value to set (unused)
     * @return never Never returns (always throws)
     * @throws RtItemReadOnlyException Always thrown
     */
    final public function __set(string $name, mixed $value): never
    {
        $className = static::class;
        throw new RtItemReadOnlyException(
            "Cannot set property [{$name}] on {$className}: RtItem is read-only. Use actions for modifications."
        );
    }

    /**
     * Converts item to associative array.
     *
     * @return array<string, mixed> Item data as array
     */
    abstract public function toArray(): array;

    /**
     * Returns debug representation (same as toArray).
     *
     * @return array<string, mixed> Item data for var_dump etc
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

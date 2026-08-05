<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Context;

use Closure;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Rt\RtCloneException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateItemNotFoundException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Actions\Item\BackupRuntimeActions;
use Hilos\Runtime\View\Actions\Item\ProtectedModeRuntimeActions;
use Hilos\Runtime\View\Actions\Item\RtActions as RtItemActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Item\RtItem;

/**
 * RtContext - runtime context (transient application data).
 *
 * Manages state collections and their runtime view wrappers.
 * Runtime data is transient - it lives only in memory for the process lifetime.
 *
 * @property-read ?BackupRuntime $hilosBackupRuntime Backup subsystem runtime singleton, or null when unmounted
 * @property-read ?ProtectedModeRuntime $hilosProtectedModeRuntime Protected mode runtime singleton, or null when unmounted
 */
abstract class RtContext
{
    /** @var array<string, RtStates> Map of collection name to state collection */
    protected array $_stateCollections = [];

    /** @var array<string, RtCollection> Map of collection name to runtime view collection */
    protected array $_rtCollections = [];

    /**
     * Context item aliases registered by child contexts before setRepresentItem().
     *
     * Resolvers return a backing state row for the current execution context, or
     * null when the alias has no item available. They must not return view items
     * or raw arrays.
     *
     * @var array<string, RtState|Closure(): ?RtState>
     */
    protected array $_stateItems = [];

    /**
     * Context item view representations registered by setRepresentItem().
     *
     * The parent runtime collection is inferred from the resolved state row when
     * it belongs to a represented state collection. This keeps single-item
     * aliases usable for collection-backed rows and for future standalone items.
     *
     * @var array<string, array{
     *     itemClass: class-string<RtItem>,
     *     itemActionsClass: ?class-string<RtItemActions>
     * }>
     */
    protected array $_rtItems = [];

    /**
     * Creates runtime context.
     *
     * Called from facade createRuntime().
     */
    public function __construct()
    {
        $this->representFrameworkItems();
    }

    /**
     * Declares the view representation of the framework-owned runtime singletons.
     *
     * The framework owns both rows and every caller that reads them, so it declares how
     * they are seen; the project decides only whether the backing row is mounted at all.
     * That is why the representation is written straight into {@see self::$_rtItems}
     * instead of going through {@see self::setRepresentItem()}: that method demands an
     * already-mounted state row, while here the alias must exist even when nothing is
     * mounted — a registered alias with no row resolves to null, which is exactly what
     * {@see self::getStateItem()} used to return, whereas an unregistered one would throw
     * {@see RtCollectionNotFoundException} at every reader of an optional subsystem.
     */
    private function representFrameworkItems(): void
    {
        $this->_rtItems[StateBackupRuntime::RT_ITEM] = [
            'itemClass' => BackupRuntime::class,
            'itemActionsClass' => BackupRuntimeActions::class,
        ];
        $this->_rtItems[StateProtectedModeRuntime::RT_ITEM] = [
            'itemClass' => ProtectedModeRuntime::class,
            'itemActionsClass' => ProtectedModeRuntimeActions::class,
        ];
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
     * Configure state collections and runtime view representations.
     *
     * Called from facade init() after createRuntime().
     */
    abstract public function configure(): void;

    /**
     * Mounts the framework-owned runtime state every project gets for free.
     *
     * Called from facade init() right AFTER {@see self::configure()}: the framework goes
     * last, so a project can neither forget the row nor shadow it with an alias of its
     * own. The protected mode singleton is mounted here because a node freezing itself
     * for a destructive operation is a data-integrity guarantee, not an opt-in project
     * feature - every project that can run such an operation must be able to freeze.
     *
     * A project whose createRuntime() returns null has no context to mount into and
     * therefore no protected mode; introducing a destructive operation there means
     * introducing an RtContext first.
     */
    final public function mountFrameworkState(): void
    {
        $this->_stateItems[StateProtectedModeRuntime::RT_ITEM] = StateProtectedModeRuntime::create();
    }

    /**
     * Set representation for state collection.
     *
     * @param string $name Collection name
     * @param class-string<RtCollection> $rtCollectionClass RtCollection class name
     * @param ?class-string<RtActions> $actionsClass RtActions class name (optional)
     * @param ?class-string<RtItemActions> $itemActionsClass Per-item RtActions class (optional)
     * @throws StateCollectionNotFoundException When state collection not found
     */
    public function setRepresent(
        string $name,
        string $rtCollectionClass,
        ?string $actionsClass = null,
        ?string $itemActionsClass = null,
    ): void {
        $stateCollection = $this->_stateCollections[$name]
            ?? throw new StateCollectionNotFoundException(
                "State collection [{$name}] not found in _stateCollections. Create it before calling setRepresent()."
            );

        if (is_subclass_of($rtCollectionClass, RtCollection::class)) {
            $rtCollection = $rtCollectionClass::init();
            $rtCollection->setStateCollection($stateCollection);
            $rtCollection->setCollectionName($name);
            if ($actionsClass !== null) {
                $rtCollection->setActionsClass($actionsClass);
            }
            if ($itemActionsClass !== null) {
                $rtCollection->setItemActionsClass($itemActionsClass);
            }
            $this->_rtCollections[$name] = $rtCollection;
        }
    }

    /**
     * Set representation for one registered state item alias.
     *
     * If the resolved state row belongs to a represented runtime collection,
     * the created item is attached to that collection so item actions can use
     * the normal truth-source, sync, remove, and cache contracts.
     *
     * @param string $name Context item alias exposed via magic getter
     * @param class-string<RtItem> $rtItemClass RtItem class name
     * @param ?class-string<RtItemActions> $itemActionsClass Per-item RtActions class
     * @throws StateItemNotFoundException When state item alias is not registered
     */
    public function setRepresentItem(
        string $name,
        string $rtItemClass,
        ?string $itemActionsClass = null,
    ): void {
        if (!array_key_exists($name, $this->_stateItems)) {
            throw new StateItemNotFoundException(
                "State item [{$name}] not found in _stateItems. Create it before calling setRepresentItem()."
            );
        }

        $this->_rtItems[$name] = [
            'itemClass' => $rtItemClass,
            'itemActionsClass' => $itemActionsClass,
        ];
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
     * Get runtime collection or item alias by name.
     *
     * @param string $name Collection or item alias name
     * @return RtCollection|RtItem|null Runtime collection, item alias result, or null for a missing item alias
     * @throws RtCollectionNotFoundException When name is neither a collection nor an item alias
     */
    public function __get(string $name): RtCollection|RtItem|null
    {
        if (isset($this->_rtCollections[$name])) {
            return $this->_rtCollections[$name];
        }
        if (isset($this->_rtItems[$name])) {
            return $this->getRtItem($name);
        }

        throw new RtCollectionNotFoundException("Runtime collection or item [{$name}] does not exist");
    }

    /**
     * Check whether a runtime collection exists or an item alias currently resolves.
     *
     * @param string $name Collection or item alias name
     */
    public function __isset(string $name): bool
    {
        if (isset($this->_rtCollections[$name])) {
            return true;
        }
        if (isset($this->_rtItems[$name])) {
            return $this->getStateItem($name) !== null;
        }

        return false;
    }

    /**
     * Resolve a registered state item alias for the current execution context.
     *
     * @param string $name Context item alias name
     * @return ?RtState Backing state row or null when unavailable
     */
    public function getStateItem(string $name): ?RtState
    {
        $stateItem = $this->_stateItems[$name] ?? null;

        return $stateItem instanceof Closure ? $stateItem() : $stateItem;
    }

    /**
     * Build the view item for a resolved state item alias.
     *
     * @param string $name Context item alias name
     * @return ?RtItem Runtime view item or null when the state alias resolves to null
     */
    private function getRtItem(string $name): ?RtItem
    {
        $stateItem = $this->getStateItem($name);
        if ($stateItem === null) {
            return null;
        }

        $itemConfig = $this->_rtItems[$name];
        $class = $itemConfig['itemClass'];
        $item = new $class($stateItem);
        $rtCollection = $this->getRtCollectionForStateItem($stateItem);
        if ($rtCollection !== null) {
            $item->setCollection($rtCollection);
        }
        $item->setItemActionsClass($itemConfig['itemActionsClass']);

        return $item;
    }

    /**
     * Find a represented runtime collection that owns the given state row.
     *
     * @param RtState $stateItem Backing state row
     * @return ?RtCollection Parent runtime collection or null for standalone state items
     */
    private function getRtCollectionForStateItem(RtState $stateItem): ?RtCollection
    {
        $stateId = $stateItem->getId();
        foreach ($this->_stateCollections as $name => $stateCollection) {
            if (!isset($this->_rtCollections[$name], $stateCollection[$stateId])) {
                continue;
            }
            if ($stateCollection[$stateId] === $stateItem) {
                return $this->_rtCollections[$name];
            }
        }

        return null;
    }

    /**
     * Convert all collections to array.
     *
     * @return array<string, array<string|int, array<string, mixed>>> Collection name => items array
     * @throws RtActionsStateCollectionNullException When a represented runtime collection has no backing state collection
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
     * @return array<string, array<string|int, array<string, mixed>>> Collection name => items array
     * @throws RtActionsStateCollectionNullException When a represented runtime collection has no backing state collection
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

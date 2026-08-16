<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Context;

use Closure;
use Hilos\Core\Feature\Exception\FeatureRuntimeOverwrittenException;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Rt\RtCloneException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateItemNotFoundException;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Collection\HilosSessionRotations as StateHilosSessionRotations;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosSessionRotationsActions;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Actions\Item\BackupRuntimeActions;
use Hilos\Runtime\View\Actions\Item\ProtectedModeRuntimeActions;
use Hilos\Runtime\View\Actions\Item\RestoreRuntimeActions;
use Hilos\Runtime\View\Actions\Item\RtActions as RtItemActions;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\Collection\HilosSessionConnections as ViewHilosSessionConnections;
use Hilos\Runtime\View\Collection\HilosSessionRotations;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Item\RestoreRuntime;
use Hilos\Runtime\View\Item\RtItem;

/**
 * RtContext - runtime context (transient application data).
 *
 * Manages state collections and their runtime view wrappers.
 * Runtime data is transient - it lives only in memory for the process lifetime.
 *
 * @property-read BackupHistories $hilosBackupHistories Stored-backup index, mounted for a project that declares HilosFeature::BACKUP
 * @property-read HilosSessionRotations $hilosSessionRotations Pending login token rotations, mounted for every project
 * @property-read ?BackupRuntime $hilosBackupRuntime Backup subsystem runtime singleton, or null when unmounted
 * @property-read ?RestoreRuntime $hilosRestoreRuntime Restore run runtime singleton, or null when unmounted
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
     * Framework-owned state collections, keyed by name, with the feature that mounted each.
     *
     * @var array<string, array{feature: ?HilosFeature, state: RtStates}>
     */
    private array $_featureCollections = [];

    /**
     * Framework-owned state rows, keyed by alias, with the feature that mounted each.
     *
     * @var array<string, array{feature: ?HilosFeature, state: RtState}>
     */
    private array $_featureItems = [];

    /** @var ?HilosFeature Feature whose mount() is running, null while the framework mounts its own */
    private ?HilosFeature $_mountingFeature = null;

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
        $this->_rtItems[StateRestoreRuntime::RT_ITEM] = [
            'itemClass' => RestoreRuntime::class,
            'itemActionsClass' => RestoreRuntimeActions::class,
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
     *
     * @throws HilosException Whatever the project's wiring of its collections raises
     */
    abstract public function configure(): void;

    /**
     * Mounts the runtime state the framework owns: the always-on rows and the declared features.
     *
     * Called from facade init() right BEFORE {@see self::configure()}, so the project builds its
     * own state on top of a context that already carries what it declared. Mounting first and
     * checking afterwards ({@see self::assertFeatureRuntimeIntact()}) is what makes the
     * declaration the only switch: a project cannot forget a row of a feature it declared, and
     * cannot quietly replace one either - the check names the key and the line to delete.
     *
     * Two of these are mounted unconditionally and before any feature, because neither is an
     * opt-in surface. The protected mode singleton is there because a node freezing itself for
     * a destructive operation is a data-integrity guarantee: every project that can run such an
     * operation must be able to freeze. The session rotations are there because the login token
     * rotation (HIL-582) is a security guarantee of the session seam, and sessions are not a
     * declared feature at all - a project carries them by standing its connections on the
     * session stage, which is only known after {@see self::configure()} has run, too late to
     * mount from. In a project without sessions the collection is inert: nothing registers a
     * truth source for it, so nothing can write it, and the handshake finds no row and serves
     * the ordinary cookie rule.
     *
     * A project whose createRuntime() returns null has no context to mount into; declaring a
     * feature that brings runtime state there is refused by the facade instead, since there is
     * nothing here to refuse it with.
     *
     * @param list<FeatureDefinition> $definitions Definitions of the features the project declared
     * @throws StateCollectionNotFoundException When a definition represents a collection it did not mount
     */
    final public function mountFeatureRuntime(array $definitions): void
    {
        $this->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        $this->mountFeatureCollection(StateHilosSessionRotation::RT_COLLECTION, StateHilosSessionRotations::init());
        $this->setRepresent(
            StateHilosSessionRotation::RT_COLLECTION,
            HilosSessionRotations::class,
            HilosSessionRotationsActions::class,
        );

        foreach ($definitions as $definition) {
            $this->_mountingFeature = $definition->feature();
            $definition->mount($this);
        }

        $this->_mountingFeature = null;
    }

    /**
     * Fails when the project replaced runtime state the framework mounted for it.
     *
     * Called from facade init() right after {@see self::configure()}. Identity is what is
     * compared, not presence: a project that re-mounts a feature's key gets a second, empty
     * collection under the same name, and every reader that already holds the first one - the
     * agent that fills it, the page that syncs it - keeps writing into state nobody reads.
     * That failure is invisible at runtime, so it is turned into a refusal to start.
     *
     * @throws FeatureRuntimeOverwrittenException When a framework-owned runtime key was replaced or dropped
     */
    final public function assertFeatureRuntimeIntact(): void
    {
        $errors = [];
        foreach ($this->_featureCollections as $name => $mounted) {
            if (($this->_stateCollections[$name] ?? null) !== $mounted['state']) {
                $errors[] = "state collection {$name} is " . $this->ownerLabel($mounted['feature']);
            }
        }

        foreach ($this->_featureItems as $alias => $mounted) {
            if (($this->_stateItems[$alias] ?? null) !== $mounted['state']) {
                $errors[] = "state item {$alias} is " . $this->ownerLabel($mounted['feature']);
            }
        }

        if ($errors !== []) {
            throw FeatureRuntimeOverwrittenException::forErrors(static::class, $errors);
        }
    }

    /**
     * Mounts a framework-owned state collection for a declared feature.
     *
     * The only caller is {@see FeatureDefinition::mount()}: a feature owns its rows, and the
     * context owns the maps they live in, so the definition hands the collection over instead
     * of reaching into the context. A project never calls this - it registers its own state in
     * {@see self::configure()}, and a key that belongs to a feature is refused there.
     *
     * @param string $name Collection name owned by the feature
     * @param RtStates $collection State collection instance to mount
     */
    final public function mountFeatureCollection(string $name, RtStates $collection): void
    {
        $this->_stateCollections[$name] = $collection;
        $this->_featureCollections[$name] = ['feature' => $this->_mountingFeature, 'state' => $collection];
    }

    /**
     * Mounts a framework-owned singleton state row for a declared feature.
     *
     * The view representation of framework singletons is declared once in
     * {@see self::representFrameworkItems()} and does not depend on the mount, so a feature
     * only supplies the backing row; an unmounted row still resolves to null for its readers.
     *
     * @param string $name Item alias owned by the feature
     * @param RtState $item State row instance to mount
     */
    final public function mountFeatureItem(string $name, RtState $item): void
    {
        $this->_stateItems[$name] = $item;
        $this->_featureItems[$name] = ['feature' => $this->_mountingFeature, 'state' => $item];
    }

    /**
     * Names who owns a framework-mounted runtime key, and what to do about it.
     *
     * @param ?HilosFeature $feature Feature that mounted the key, or null when the framework did
     * @return string Owner sentence for the overwrite error
     */
    private function ownerLabel(?HilosFeature $feature): string
    {
        $owner = $feature === null
            ? 'mounted by the framework for every project'
            : 'mounted by the framework for HilosFeature::' . $feature->name . ' declared in ' . Hilos::appClass() . '::FEATURES';

        return $owner . '; remove the line that mounts it from ' . static::class . '::configure()';
    }

    /**
     * Returns the mounted runtime collection that reports user presence, when there is one.
     *
     * At runtime the framework never asks this: the users table is bound to its presence source
     * by name, and the project's table says which name. The question with no name in it belongs
     * to the activation check - HilosFeature::HILOS_USERS requires a presence source, and the
     * only way to ask whether the project has one at all is to ask the context, since the key it
     * lives under is the project's own choice.
     *
     * @return ?HilosPresenceSource First represented collection reporting presence, or null when none does
     */
    final public function presenceSource(): ?HilosPresenceSource
    {
        foreach ($this->_rtCollections as $collection) {
            if ($collection instanceof HilosPresenceSource) {
                return $collection;
            }
        }

        return null;
    }

    /**
     * Returns the mounted state collection of WebSocket connections, when there is one.
     *
     * The mirror of {@see presenceSource()} one layer down: a framework seam that needs the
     * connection rows themselves, not a presence summary, runs inside the framework where the
     * project's collection key is unknown. A project whose connections do not extend the
     * framework base - or which keeps no connections at all - answers null.
     *
     * @return ?HilosConnections First mounted collection of framework-based connections, or null when none is
     */
    final public function connectionsSource(): ?HilosConnections
    {
        foreach ($this->_stateCollections as $collection) {
            if ($collection instanceof HilosConnections) {
                return $collection;
            }
        }

        return null;
    }

    /**
     * Returns the mounted state collection of session-carrying connections, when there is one.
     *
     * The same question one stage up (HIL-509): a seam that reads the session token asks for the
     * stage that has one, so a project standing on the presence stage answers null here while
     * still answering {@see connectionsSource()}. That is how the session carry-over (HIL-479)
     * finds the tokens it photographs, and why a project without sessions has nothing to carry
     * rather than an activation error to report.
     *
     * @return ?HilosSessionConnections First mounted collection of session-stage connections, or null when none is
     */
    final public function sessionConnectionsSource(): ?HilosSessionConnections
    {
        foreach ($this->_stateCollections as $collection) {
            if ($collection instanceof HilosSessionConnections) {
                return $collection;
            }
        }

        return null;
    }

    /**
     * Returns the represented collection of session-carrying connections, when there is one.
     *
     * The write-side twin of {@see sessionConnectionsSource()} (HIL-582). A framework seam
     * that only reads the rows takes the state collection; one that has to CHANGE a row takes
     * this, because a write outside the represented collection's actions lands in a single
     * worker and is never synced. The login rotation is such a seam: it re-points the
     * initiating connection onto the token its session was renamed to.
     *
     * A project standing on the presence stage, or one that mounted its connections without
     * representing them, answers null - and the seam then has no row to move, which is the
     * same nothing-to-do a project without sessions has.
     *
     * @return ?ViewHilosSessionConnections First represented collection of session-stage connections, or null
     */
    final public function sessionConnectionsRegistry(): ?ViewHilosSessionConnections
    {
        foreach ($this->_rtCollections as $collection) {
            if ($collection instanceof ViewHilosSessionConnections) {
                return $collection;
            }
        }

        return null;
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
     * Reports whether a name is a runtime source this context mounts - a represented collection
     * or a declared item alias, the two things {@see self::__get()} can answer with.
     *
     * Its own predicate rather than one of the two that nearly fit: {@see self::__isset()}
     * answers false for an item alias that merely does not resolve outside a subscription, which
     * would read as a missing mount, and {@see self::getStateCollection()} does not see aliases
     * at all. What is asked here is what the project mounted, not what the current execution
     * context can reach.
     *
     * @param string $name Collection or item alias name
     * @return bool True when the name is a represented collection or a declared item alias
     */
    public function hasSource(string $name): bool
    {
        return isset($this->_rtCollections[$name]) || array_key_exists($name, $this->_rtItems);
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

<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;

/**
 * Base class for Db collection actions.
 * Collection actions are for create, bulk, and collection-wide writes.
 * One-item update/delete operations belong to the loaded DbItem actions.
 *
 * Usage:
 *   $user = Hilos::$db->users->actions->createWithName($name);
 *   $event = Hilos::$db->events->actions->add($type, $userId, $data);
 *
 * @template T of DbItem
 * @template TObjectCollection of Objects
 * @property-read DbCollection<T, TObjectCollection> $collection DbCollection instance this actions belong to
 * @property-read Objects $objectCollection ObjectCollection shortcut (via __get)
 */
abstract class DbActions
{
    public const string objectCollection = 'objectCollection';

    /**
     * DbCollection instance this actions belong to
     * Type is declared via property-read in child classes
     *
     * @var DbCollection<T, TObjectCollection>
     */
    protected DbCollection $collection;

    /**
     * Callback for creating DbItem from Object
     * Set by DbCollection via setCreateDbItemCallback()
     *
     * @var callable(Object_): DbItem|null
     */
    private $createDbItemCallback = null;

    /**
     * Callback for notifying DbCollection about mass changes (e.g. deleteAll()).
     * Set by DbCollection via setClearCacheCallback().
     *
     * @var callable(): void|null
     */
    private $clearCacheCallback = null;

    /**
     * Creates DbActions with DbCollection instance.
     *
     * @param DbCollection<T, TObjectCollection> $collection DbCollection instance
     */
    public function __construct(DbCollection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Magic getter for objectCollection shortcut.
     *
     * @param string $name Property name (objectCollection)
     * @return Objects Object collection instance
     * @throws ObjectCollectionNullException If ObjectCollection is null (manual collection)
     * @throws InvalidArgumentException When the property name is not a known DbActions property
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::objectCollection => $this->getObjectCollection()
                ?? throw new ObjectCollectionNullException("ObjectCollection is null (manual collection)"),
            default => throw new InvalidArgumentException("Unknown property: {$name}"),
        };
    }

    /**
     * Set callback for creating DbItem from Object
     * Called by DbCollection when Actions is created
     *
     * @param callable(Object_): DbItem $callback Callback function
     */
    public function setCreateDbItemCallback(callable $callback): void
    {
        $this->createDbItemCallback = $callback;
    }

    /**
     * Set callback for clearing DbCollection cache.
     * Called by DbCollection when Actions is created.
     *
     * @param callable(): void $callback Callback to clear cache
     */
    public function setClearCacheCallback(callable $callback): void
    {
        $this->clearCacheCallback = $callback;
    }

    /**
     * Create DbItem from Object using callback
     *
     * @param Object_ $object Object instance
     * @return T DbItem instance, resolved to specific subtype via generic parameter T
     * @throws CallbackNotSetException If callback is not set
     */
    protected function createDbItemFromObject(Object_ &$object): DbItem
    {
        if ($this->createDbItemCallback === null) {
            throw new CallbackNotSetException('createDbItemCallback is not set.'
                . ' DbCollection must call setCreateDbItemCallback() when creating Actions.');
        }
        return ($this->createDbItemCallback)($object);
    }

    /**
     * Clear DbCollection cache via callback.
     * Used after mass-mutations on underlying ObjectCollection (e.g. deleteAll()),
     * so DbCollection doesn't return stale DbItem instances from its internal cache.
     *
     * @throws CallbackNotSetException If callback is not set
     */
    protected function clearCollectionCache(): void
    {
        if ($this->clearCacheCallback === null) {
            throw new CallbackNotSetException("clearCacheCallback is not set. DbCollection must call setClearCacheCallback() when creating Actions.");
        }
        ($this->clearCacheCallback)();
    }

    /**
     * Get ObjectCollection for this Actions
     * Returns reference to storage - modifications will affect the stored collection
     *
     * @return ?Objects ObjectCollection instance, or null for manual collections
     */
    protected function getObjectCollection(): ?Objects
    {
        /** @var ?Objects $objectCollection */
        $objectCollection = $this->collection->getObjectCollection();
        return $objectCollection;
    }

    /**
     * Get table name from the ObjectCollection.
     * Converts any failure to resolve the table name into TableNameUndeterminedException.
     *
     * @return string Table name
     * @throws TableNameUndeterminedException If the table name cannot be determined
     */
    protected function getTableName(): string
    {
        try {
            return $this->objectCollection->getTableName();
        } catch (\Exception $e) {
            throw new TableNameUndeterminedException(
                'Cannot determine table name: collection is empty.'
                    . ' Override getTableName() in Actions class if needed.',
                0,
                $e,
            );
        }
    }

    /**
     * Ensure write is allowed and data is loaded if needed
     * Checks TruthSourceRegistry and loads data based on lazy loading strategy
     *
     * @throws UnknownLazyStrategyException If unknown lazy loading strategy
     * @throws WriteNotAllowedException If write is not allowed
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException On connection or load error
     */
    protected function ensureCanWrite(): void
    {
        $objectCollection = $this->objectCollection;

        switch ($objectCollection->getLazyStrategy()) {
            case Objects::LAZY_STRATEGY_NONE:
                $collectionKey = $objectCollection->getCollectionKey();
                TruthSourceRegistry::checkCanWrite($collectionKey);
                if (!$objectCollection->isAllLoaded()) {
                    $objectCollection->loadAllFromDB();
                }
                break;

            case Objects::LAZY_STRATEGY_KEY:
                break;

            case Objects::LAZY_STRATEGY_BATCH:
                break;

            case Objects::LAZY_STRATEGY_FULL_ON_ACCESS:
                break;

            default:
                throw new UnknownLazyStrategyException("Unknown lazy loading strategy for write check");
        }
    }

    /**
     * Ensure create is allowed and data is loaded if needed
     * Checks TruthSourceRegistry::checkCanCreate for create permission
     *
     * @throws UnknownLazyStrategyException If unknown lazy loading strategy
     * @throws CreateNotAllowedException If create is not allowed
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException On connection or load error
     */
    protected function ensureCanCreate(): void
    {
        $objectCollection = $this->objectCollection;

        switch ($objectCollection->getLazyStrategy()) {
            case Objects::LAZY_STRATEGY_NONE:
                $collectionKey = $objectCollection->getCollectionKey();
                TruthSourceRegistry::checkCanCreate($collectionKey);
                if (!$objectCollection->isAllLoaded()) {
                    $objectCollection->loadAllFromDB();
                }
                break;

            case Objects::LAZY_STRATEGY_KEY:
                break;

            case Objects::LAZY_STRATEGY_BATCH:
                break;

            case Objects::LAZY_STRATEGY_FULL_ON_ACCESS:
                break;

            default:
                throw new UnknownLazyStrategyException("Unknown lazy loading strategy for create check");
        }
    }

    /**
     * Add Object to ObjectCollection
     * Adds object to the global ObjectCollection storage
     * Checks for duplicate IDs and throws exception if object already exists
     *
     * @param Object_ $object Object instance to add
     * @throws ObjectGetIdStringNotImplementedException When the object primary key is null
     * @throws DuplicateIdException If object with same ID already exists and the DB was not replaced
     * @throws TableNameUndeterminedException If table name cannot be determined
     * @throws LogicException When a represented collection entity class is not configured (re-hydrate reload)
     * @throws DatabaseException If reloading an eager collection from the fresh DB fails (re-hydrate)
     */
    protected function addObjectToCollection(Object_ $object): void
    {
        $idString = $object->getIdString();
        if (isset($this->objectCollection[$idString])) {
            // A collision is a genuine duplicate only if the DB is the same one the
            // in-memory row came from. After an external db-reset/restore the
            // autoincrement restarts and the fresh id clashes with a stale row; a
            // detected DB generation change re-hydrates the collections, turning the
            // collision into a clean insert instead of a DuplicateIdException.
            if (Hilos::$db?->reHydrateIfDbChanged() === true) {
                $this->objectCollection[$idString] = $object;
                return;
            }
            $table = $this->getTableName();
            throw new DuplicateIdException("Cannot add object to collection: object with ID '{$idString}' already exists in table '{$table}'");
        }
        $this->objectCollection[$idString] = $object;
    }
}

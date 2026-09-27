<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Exception;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\SetTree;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use Hilos\HilosException;

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
    protected function createDbItemFromObject(Object_ $object): DbItem
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
        } catch (Exception $e) {
            throw new TableNameUndeterminedException(
                'Cannot determine table name: collection is empty.'
                    . ' Override getTableName() in Actions class if needed.',
                0,
                $e,
            );
        }
    }

    /**
     * Ensure write is allowed
     * Asks the write guard for the right over the whole table
     *
     * The right is the only question asked at any of the four doors. Nothing is loaded before a
     * write: a collection that promised the whole table reads it on its own first read, and a row
     * a write puts into memory keeps its instance when that read comes (HIL-1144).
     *
     * The operation has no default: this door is shared by actions that insert rows in bulk, edit
     * them in bulk and delete them, and no one value is right for all of them. A door that creates
     * one row asks ensureCanCreate() or ensureCanCreateInSet() instead, which the owner of a set
     * passes too; this one asks for the whole table.
     *
     * @param TruthSourceOperation $operation Operation the caller performs across the collection: Add for an insert
     *     in bulk through the whole table, Update for a bulk edit, Remove for a delete
     * @throws WriteNotAllowedException If write is not allowed
     */
    protected function ensureCanWrite(TruthSourceOperation $operation): void
    {
        DbWriteGuard::guardCollectionWrite($this->objectCollection->getCollectionKey(), $operation);
    }

    /**
     * Ensure a write over every row of one set is allowed
     * Asks the write guard for the right over that set
     *
     * The door for a bulk write cut by one set - the rows of one person, say - where
     * ensureCanWrite() would ask for the whole table. The set column is the entity's own, so only its value is
     * named here, and the delete or edit that follows has to cut the table by that column alone. A claim over a set
     * is judged by the key that value reaches at the top of the set tree, climbed only when such a claim asks.
     *
     * @param string $setKey Value of the set column the write cuts the table by
     * @param TruthSourceOperation $operation Operation the caller performs on every row of the set: Update for a bulk
     *     edit, Remove for a delete
     * @throws WriteNotAllowedException If the write over that set is not allowed
     */
    protected function ensureCanWriteSet(string $setKey, TruthSourceOperation $operation): void
    {
        $objectClass = $this->objectCollection::OBJECT_CLASS;
        DbWriteGuard::guardSetWrite(
            $this->objectCollection->getCollectionKey(),
            $setKey,
            SetTree::climb($objectClass::ENTITY_CLASS, $setKey),
            $operation,
        );
    }

    /**
     * Ensure create is allowed
     * Asks the write guard for the create right over the whole table
     *
     * The door for a table cut by no set. On a table cut by one it names no set for the new row,
     * so it lets the owner of the whole table and the mint-only claim create, as before, and
     * refuses the owner of a set: to that claim the row belongs to nobody's set. A row that lands
     * in a set asks ensureCanCreateInSet().
     *
     * @throws CreateNotAllowedException If create is not allowed
     */
    protected function ensureCanCreate(): void
    {
        DbWriteGuard::guardCreate($this->objectCollection->getCollectionKey(), static fn(): array => []);
    }

    /**
     * Ensure creating a row in one set is allowed
     * Asks the write guard for the create right in that set
     *
     * The door for a row of a table cut by a set - the rows of one person, of one room. The set
     * column is the entity's own, so only the value the new row will carry in it is named here,
     * and a claim over a set judges it by the key that value reaches at the top of the set tree,
     * climbed only when such a claim asks. The caller passes the same value the row will be saved
     * with: the save asks the right again from the row itself, so a door and a row that disagree are
     * caught before the insert.
     *
     * @param string $setKey Value of the table's set column the new row will carry
     * @throws CreateNotAllowedException If creating a row in that set is not allowed
     */
    protected function ensureCanCreateInSet(string $setKey): void
    {
        $objectClass = $this->objectCollection::OBJECT_CLASS;
        DbWriteGuard::guardCreate($this->objectCollection->getCollectionKey(), SetTree::climb($objectClass::ENTITY_CLASS, $setKey));
    }

    /**
     * Add Object to ObjectCollection
     * Adds object to the global ObjectCollection storage
     * Checks for duplicate IDs and throws exception if object already exists
     *
     * The view cache is not touched here: the mirror announces its own new membership, and a
     * subscriber to that announcement drops the one wrapper the key no longer answers with.
     *
     * @param Object_ $object Object instance to add
     * @throws ObjectGetIdStringNotImplementedException When the object primary key is null
     * @throws DuplicateIdException If object with same ID already exists and the DB was not replaced
     * @throws TableNameUndeterminedException If table name cannot be determined
     * @throws LogicException When a represented collection entity class is not configured (re-hydrate reload)
     * @throws DatabaseException If reloading an eager collection from the fresh DB fails (re-hydrate)
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
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

    /**
     * Delete every row of the collection and clear the cache of DbItem wrappers
     * Shared body of the collection-wide deleteAll(), whose own checks and cascades
     * stay with the concrete Actions class
     *
     * @throws CallbackNotSetException If the clear-cache callback is not set
     * @throws DatabaseException If the delete fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectCollectionNullException When the collection is manual and has no ObjectCollection
     * @throws WriteNotAllowedException If write is not allowed
     */
    protected function deleteAllObjects(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        $this->objectCollection->deleteAll();

        $this->clearCollectionCache();
    }
}

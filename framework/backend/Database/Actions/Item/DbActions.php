<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Item\DbItem;

/**
 * Base class for Db item actions (write operations for a single Db item).
 *
 * @template T of DbItem
 * @template TObject of Object_
 * @property-read Object_ $object
 */
abstract class DbActions
{
    public const string object = 'object';

    /** @var DbItem DbItem instance these actions belong to */
    protected DbItem $item;

    /**
     * Creates Db item actions instance.
     *
     * @param DbItem $item DbItem instance
     */
    public function __construct(DbItem $item)
    {
        $this->item = $item;
    }

    /**
     * Returns object property.
     *
     * @param string $name Property name (object only)
     * @return Object_ Object instance
     * @throws InvalidArgumentException If property unknown
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::object => $this->item->getObject(),
            default => throw new InvalidArgumentException("Unknown property: {$name}"),
        };
    }

    /**
     * Gets object collection for this item.
     *
     * @return ?Objects Object collection or null if no parent
     */
    protected function getObjectCollection(): ?Objects
    {
        return $this->item->getObjectCollection();
    }

    /**
     * Ensures write is allowed.
     *
     * Defaults to editing because that is what an item action does: the row is already there,
     * held by this very item, and the ones that instead drop it name the operation themselves.
     * Minting a new row goes through the collection's create guard instead.
     *
     * The right is the only question asked here. Nothing is loaded before a write: a collection
     * that promised the whole table reads it on its own first read, and the row this write puts
     * into memory keeps its instance when that read comes (HIL-1144).
     *
     * @param TruthSourceOperation $operation Operation the caller is about to perform
     * @throws ObjectCollectionNullException If object collection is null (manual)
     * @throws ObjectGetIdStringNotImplementedException When the item primary key is null during the per-item write check
     * @throws WriteNotAllowedException If write not allowed by truth source
     * @throws CreateNotAllowedException If the row is not in the database yet and creating it is not allowed
     */
    protected function ensureCanWrite(TruthSourceOperation $operation = TruthSourceOperation::Update): void
    {
        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException("ObjectCollection is null (manual collection)");

        $collectionKey = $objectCollection->getCollectionKey();
        if ($this->object->isRelated()) {
            DbWriteGuard::guardItemWrite(
                $collectionKey,
                $this->object->getIdString(),
                $this->object->touchedSetKeys(...),
                $operation,
            );
        } else {
            // The row is not in the database yet, so an insert is the one write it can receive: the door asks the right to
            // create in the set it lands in, whatever the caller named.
            DbWriteGuard::guardCreate($collectionKey, $this->object->touchedSetKeys(...));
        }
    }
}

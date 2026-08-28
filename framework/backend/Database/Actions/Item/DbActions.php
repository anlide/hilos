<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
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
     * Ensures write is allowed and collection is loaded if needed.
     *
     * The operation is editing: an item action holds a record that already exists, and minting
     * a new one goes through the collection's create guard instead.
     *
     * The right is asked before the strategy is looked at, because the two answer different
     * questions: who may write this table, and how much of it has to be in memory first. The
     * switch below is left with the second one only.
     *
     * @throws ObjectCollectionNullException If object collection is null (manual)
     * @throws ObjectGetIdStringNotImplementedException When the item primary key is null during the per-item write check
     * @throws UnknownLazyStrategyException If lazy strategy is unknown
     * @throws WriteNotAllowedException If write not allowed by truth source
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException If load fails
     */
    protected function ensureCanWrite(): void
    {
        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException("ObjectCollection is null (manual collection)");

        $collectionKey = $objectCollection->getCollectionKey();
        if ($this->object->isRelated()) {
            DbWriteGuard::guardItemWrite(
                $collectionKey,
                $this->object->getIdString(),
                TruthSourceOperation::Update,
            );
        } else {
            DbWriteGuard::guardCollectionWrite($collectionKey);
        }

        switch ($objectCollection->getLazyStrategy()) {
            case Objects::LAZY_STRATEGY_NONE:
                if (!$objectCollection->isAllLoaded()) {
                    $objectCollection->loadAllFromDB();
                }
                break;

            case Objects::LAZY_STRATEGY_KEY:
            case Objects::LAZY_STRATEGY_BATCH:
            case Objects::LAZY_STRATEGY_FULL_ON_ACCESS:
                break;

            default:
                throw new UnknownLazyStrategyException("Unknown lazy loading strategy for write check");
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
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
     * @throws \InvalidArgumentException If property unknown
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::object => $this->item->getObject(),
            default => throw new \InvalidArgumentException("Unknown property: {$name}"),
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
     * @throws ObjectCollectionNullException If object collection is null (manual)
     * @throws UnknownLazyStrategyException If lazy strategy is unknown
     * @throws WriteNotAllowedException If write not allowed by truth source
     * @throws DatabaseException If load fails
     */
    protected function ensureCanWrite(): void
    {
        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException("ObjectCollection is null (manual collection)");

        switch ($objectCollection->getLazyStrategy()) {
            case Objects::LAZY_STRATEGY_NONE:
                $collectionKey = $objectCollection->getCollectionKey();
                if ($this->object->isRelated()) {
                    TruthSourceRegistry::checkCanWriteItem($collectionKey, $this->object->getIdString());
                } else {
                    TruthSourceRegistry::checkCanWrite($collectionKey);
                }
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
}

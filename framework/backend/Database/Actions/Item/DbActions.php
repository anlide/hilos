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

    /**
     * DbItem instance this actions belong to.
     *
     * @var DbItem
     */
    protected DbItem $item;

    /**
     * @param DbItem $item DbItem instance
     */
    public function __construct(DbItem $item)
    {
        $this->item = $item;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            self::object => $this->item->getObject(),
            default => throw new \InvalidArgumentException("Unknown property: {$name}"),
        };
    }

    /**
     * Get object collection for this item.
     *
     * @return ?Objects
     */
    protected function getObjectCollection(): ?Objects
    {
        return $this->item->getObjectCollection();
    }

    /**
     * Ensure write is allowed and collection is loaded if needed.
     *
     * @throws ObjectCollectionNullException
     * @throws UnknownLazyStrategyException
     * @throws WriteNotAllowedException
     * @throws DatabaseException
     */
    protected function ensureCanWrite(): void
    {
        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException("ObjectCollection is null (manual collection)");

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
}

<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\AccountErasureOperations as ObjectAccountErasureOperations;
use Hilos\Database\Object\Item\AccountErasureOperation as ObjectAccountErasureOperation;
use Hilos\Database\View\Item\AccountErasureOperation;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\HilosException;

/**
 * Array offsets are primary ids; use isset or ?? null for optional rows.
 *
 * @extends DbCollection<AccountErasureOperation, ObjectAccountErasureOperations>
 * @method ObjectAccountErasureOperations|null getObjectCollection()
 * @method AccountErasureOperation|null first()
 * @method AccountErasureOperation|null last()
 * @method AccountErasureOperation offsetGet(mixed $offset)
 */
class AccountErasureOperations extends DbCollection
{
    public const string DB_ITEM_CLASS = AccountErasureOperation::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountErasureOperations::class;

    /**
     * @param int $requestId Original deletion request id
     * @return ?AccountErasureOperation Operation for this request
     * @throws HilosException When the storage reader, validation or item wrapper refuses the request
     */
    public function byRequest(int $requestId): ?AccountErasureOperation
    {
        return $this->itemFor($this->objectCollection->byRequest($requestId));
    }

    /**
     * @param int $afterId Exclusive primary id cursor
     * @param int $limit Page size from one through the storage page ceiling
     * @return static Open operations in ascending primary id order
     * @throws HilosException When the storage reader, validation or item wrapper refuses the request
     */
    public function openPage(int $afterId, int $limit): static
    {
        return $this->selectionFor($this->objectCollection->openPage($afterId, $limit));
    }

    /**
     * @param ?ObjectAccountErasureOperation $object Persisted object, or domain absence
     * @return ?AccountErasureOperation Its wrapper, retaining already issued object identity
     * @throws InvalidArgumentException When the object type does not match
     * @throws LogicException When the collection class links are missing
     */
    private function itemFor(?ObjectAccountErasureOperation $object): ?AccountErasureOperation
    {
        if ($object === null || $object->id === null) {
            return null;
        }

        return $this->getOrCreateItemForLoadedObject($object->id, $object);
    }

    /**
     * @param ObjectAccountErasureOperations $objects Ordered persisted selection
     * @return static Ordered manual selection of view items
     * @throws HilosException When a wrapper or manual selection rejects an item
     */
    private function selectionFor(ObjectAccountErasureOperations $objects): static
    {
        $selection = static::initEmpty();
        foreach ($objects as $object) {
            $item = $this->itemFor($object);
            if ($item !== null) {
                $selection->add($item);
            }
        }

        return $selection;
    }
}

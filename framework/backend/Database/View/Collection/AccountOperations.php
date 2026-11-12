<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Database\Object\Collection\AccountOperations as ObjectAccountOperations;
use Hilos\Database\Object\Item\AccountOperation as ObjectAccountOperation;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\HilosException;

/**
 * Array offsets are primary ids; use isset or ?? null for optional rows.
 *
 * @extends DbCollection<AccountOperation, ObjectAccountOperations>
 * @method ObjectAccountOperations|null getObjectCollection()
 * @method AccountOperation|null first()
 * @method AccountOperation|null last()
 * @method AccountOperation offsetGet(mixed $offset)
 */
class AccountOperations extends DbCollection
{
    public const string DB_ITEM_CLASS = AccountOperation::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountOperations::class;

    /**
     * @param int $requestId Original deletion request id
     * @return ?AccountOperation Operation for this request
     * @throws HilosException When the storage reader, validation or item wrapper refuses the request
     */
    public function byRequest(int $requestId): ?AccountOperation
    {
        return $this->itemFor($this->objectCollection->byRequest($requestId));
    }

    /**
     * @param int $loserUserId Immutable loser account id
     * @return ?AccountOperation Open merge for this loser
     * @throws HilosException When the storage reader or item wrapper refuses the lookup
     */
    public function openMergeOfLoser(int $loserUserId): ?AccountOperation
    {
        return $this->itemFor($this->objectCollection->openMergeOfLoser($loserUserId));
    }

    /**
     * @param AccountOperationKind $kind Required operation kind
     * @param int $afterId Exclusive primary id cursor
     * @param int $limit Page size from one through the storage page ceiling
     * @return static Open operations in ascending primary id order
     * @throws HilosException When the storage reader, validation or item wrapper refuses the request
     */
    public function openPage(AccountOperationKind $kind, int $afterId, int $limit): static
    {
        return $this->selectionFor($this->objectCollection->openPage($kind, $afterId, $limit));
    }

    /**
     * SCAFFOLD: no live caller until the erasure opening (HIL-1952) and the merge refusal (HIL-1960).
     *
     * @param int $userId Account asked about
     * @return ?AccountOperation First open erasure whose fixed circle contains the account
     * @throws HilosException When the storage reader, a stored circle or the item wrapper refuses the lookup
     */
    public function openErasureCovering(int $userId): ?AccountOperation
    {
        return $this->itemFor($this->objectCollection->openErasureCovering($userId));
    }

    /**
     * @param ?ObjectAccountOperation $object Persisted object, or domain absence
     * @return ?AccountOperation Its wrapper, retaining already issued object identity
     * @throws InvalidArgumentException When the object type does not match
     * @throws LogicException When the collection class links are missing
     */
    private function itemFor(?ObjectAccountOperation $object): ?AccountOperation
    {
        if ($object === null || $object->id === null) {
            return null;
        }

        return $this->getOrCreateItemForLoadedObject($object->id, $object);
    }

    /**
     * @param ObjectAccountOperations $objects Ordered persisted selection
     * @return static Ordered manual selection of view items
     * @throws HilosException When a wrapper or manual selection rejects an item
     */
    private function selectionFor(ObjectAccountOperations $objects): static
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

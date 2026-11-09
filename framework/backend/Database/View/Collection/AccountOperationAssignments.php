<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\AccountOperationAssignments as ObjectAccountOperationAssignments;
use Hilos\Database\Object\Item\AccountOperationAssignment as ObjectAccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\HilosException;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;

/**
 * Array offsets are primary ids; use isset or ?? null for optional rows.
 *
 * @extends DbCollection<AccountOperationAssignment, ObjectAccountOperationAssignments>
 * @method ObjectAccountOperationAssignments|null getObjectCollection()
 * @method AccountOperationAssignment|null first()
 * @method AccountOperationAssignment|null last()
 * @method AccountOperationAssignment offsetGet(mixed $offset)
 */
class AccountOperationAssignments extends DbCollection
{
    public const string DB_ITEM_CLASS = AccountOperationAssignment::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountOperationAssignments::class;

    /**
     * @param int $operationId Parent operation id
     * @param string $stage Literal stage key
     * @param string $assignmentKey Literal part identity
     * @return ?AccountOperationAssignment Matching immutable assignment
     * @throws HilosException When the storage reader, validation or item wrapper refuses the request
     */
    public function byIdentity(int $operationId, string $stage, string $assignmentKey): ?AccountOperationAssignment
    {
        return $this->itemFor($this->objectCollection->byIdentity($operationId, $stage, $assignmentKey));
    }

    /**
     * @param int $operationId Parent operation id
     * @param string $stage Literal stage key
     * @return static Assignments in ascending primary id order
     * @throws HilosException When the storage reader, validation or item wrapper refuses the request
     */
    public function forOperationStage(int $operationId, string $stage): static
    {
        return $this->selectionFor($this->objectCollection->forOperationStage($operationId, $stage));
    }

    /**
     * @param AccountOperationKind $kind Required parent operation kind
     * @param AccountOperationOwnerAddress $owner Exact stable participant address
     * @param int $afterId Exclusive primary id cursor
     * @param int $limit Bounded page size
     * @return static Assignments of open operations in ascending primary id order
     * @throws HilosException When the storage reader, validation or item wrapper refuses the request
     */
    public function openForOwner(
        AccountOperationKind $kind,
        AccountOperationOwnerAddress $owner,
        int $afterId,
        int $limit,
    ): static {
        return $this->selectionFor($this->objectCollection->openForOwner($kind, $owner, $afterId, $limit));
    }

    /**
     * @param ?ObjectAccountOperationAssignment $object Persisted object, or domain absence
     * @return ?AccountOperationAssignment Its wrapper, retaining already issued object identity
     * @throws InvalidArgumentException When the object type does not match
     * @throws LogicException When the collection class links are missing
     */
    private function itemFor(?ObjectAccountOperationAssignment $object): ?AccountOperationAssignment
    {
        if ($object === null || $object->id === null) {
            return null;
        }

        return $this->getOrCreateItemForLoadedObject($object->id, $object);
    }

    /**
     * @param ObjectAccountOperationAssignments $objects Ordered persisted selection
     * @return static Ordered manual selection of view items
     * @throws HilosException When a wrapper or manual selection rejects an item
     */
    private function selectionFor(ObjectAccountOperationAssignments $objects): static
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

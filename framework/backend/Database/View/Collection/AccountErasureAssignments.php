<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\AccountErasureAssignments as ObjectAccountErasureAssignments;
use Hilos\Database\Object\Item\AccountErasureAssignment as ObjectAccountErasureAssignment;
use Hilos\Database\View\Item\AccountErasureAssignment;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\HilosException;
use Hilos\Auth\AccountErasure\AccountErasureOwnerAddress;

/**
 * Array offsets are primary ids; use isset or ?? null for optional rows.
 *
 * @extends DbCollection<AccountErasureAssignment, ObjectAccountErasureAssignments>
 * @method ObjectAccountErasureAssignments|null getObjectCollection()
 * @method AccountErasureAssignment|null first()
 * @method AccountErasureAssignment|null last()
 * @method AccountErasureAssignment offsetGet(mixed $offset)
 */
class AccountErasureAssignments extends DbCollection
{
    public const string DB_ITEM_CLASS = AccountErasureAssignment::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountErasureAssignments::class;

    /**
     * @param int $operationId Parent operation id
     * @param string $stage Literal stage key
     * @param string $assignmentKey Literal part identity
     * @return ?AccountErasureAssignment Matching immutable assignment
     * @throws HilosException When the storage reader, validation or item wrapper refuses the request
     */
    public function byIdentity(int $operationId, string $stage, string $assignmentKey): ?AccountErasureAssignment
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
     * @param AccountErasureOwnerAddress $owner Exact stable participant address
     * @param int $afterId Exclusive primary id cursor
     * @param int $limit Bounded page size
     * @return static Assignments of open operations in ascending primary id order
     * @throws HilosException When the storage reader, validation or item wrapper refuses the request
     */
    public function openForOwner(AccountErasureOwnerAddress $owner, int $afterId, int $limit): static
    {
        return $this->selectionFor($this->objectCollection->openForOwner($owner, $afterId, $limit));
    }

    /**
     * @param ?ObjectAccountErasureAssignment $object Persisted object, or domain absence
     * @return ?AccountErasureAssignment Its wrapper, retaining already issued object identity
     * @throws InvalidArgumentException When the object type does not match
     * @throws LogicException When the collection class links are missing
     */
    private function itemFor(?ObjectAccountErasureAssignment $object): ?AccountErasureAssignment
    {
        if ($object === null || $object->id === null) {
            return null;
        }

        return $this->getOrCreateItemForLoadedObject($object->id, $object);
    }

    /**
     * @param ObjectAccountErasureAssignments $objects Ordered persisted selection
     * @return static Ordered manual selection of view items
     * @throws HilosException When a wrapper or manual selection rejects an item
     */
    private function selectionFor(ObjectAccountErasureAssignments $objects): static
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

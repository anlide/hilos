<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\AccountOperationResults as ObjectAccountOperationResults;
use Hilos\Database\Object\Item\AccountOperationResult as ObjectAccountOperationResult;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\HilosException;

/**
 * Array offsets are primary ids; use isset or ?? null for optional rows.
 *
 * @extends DbCollection<AccountOperationResult, ObjectAccountOperationResults>
 * @method ObjectAccountOperationResults|null getObjectCollection()
 * @method AccountOperationResult|null first()
 * @method AccountOperationResult|null last()
 * @method AccountOperationResult offsetGet(mixed $offset)
 */
class AccountOperationResults extends DbCollection
{
    public const string DB_ITEM_CLASS = AccountOperationResult::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountOperationResults::class;

    /**
     * @param int $assignmentId Assignment primary id
     * @return ?AccountOperationResult Saved result, or null before completion
     * @throws HilosException When the storage reader or item wrapper refuses the request
     */
    public function byAssignment(int $assignmentId): ?AccountOperationResult
    {
        return $this->itemFor($this->objectCollection->byAssignment($assignmentId));
    }

    /**
     * @param int $operationId Parent operation id
     * @param string $stage Literal stage key
     * @return static Results in ascending result primary id order
     * @throws HilosException When the storage reader, validation or item wrapper refuses the request
     */
    public function forOperationStage(int $operationId, string $stage): static
    {
        return $this->selectionFor($this->objectCollection->forOperationStage($operationId, $stage));
    }

    /**
     * @param ?ObjectAccountOperationResult $object Persisted object, or domain absence
     * @return ?AccountOperationResult Its wrapper, retaining already issued object identity
     * @throws InvalidArgumentException When the object type does not match
     * @throws LogicException When the collection class links are missing
     */
    private function itemFor(?ObjectAccountOperationResult $object): ?AccountOperationResult
    {
        if ($object === null || $object->id === null) {
            return null;
        }

        return $this->getOrCreateItemForLoadedObject($object->id, $object);
    }

    /**
     * @param ObjectAccountOperationResults $objects Ordered persisted selection
     * @return static Ordered manual selection of view items
     * @throws HilosException When a wrapper or manual selection rejects an item
     */
    private function selectionFor(ObjectAccountOperationResults $objects): static
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

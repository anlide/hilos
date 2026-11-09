<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\AccountErasureResults as ObjectAccountErasureResults;
use Hilos\Database\Object\Item\AccountErasureResult as ObjectAccountErasureResult;
use Hilos\Database\View\Item\AccountErasureResult;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\HilosException;

/**
 * Array offsets are primary ids; use isset or ?? null for optional rows.
 *
 * @extends DbCollection<AccountErasureResult, ObjectAccountErasureResults>
 * @method ObjectAccountErasureResults|null getObjectCollection()
 * @method AccountErasureResult|null first()
 * @method AccountErasureResult|null last()
 * @method AccountErasureResult offsetGet(mixed $offset)
 */
class AccountErasureResults extends DbCollection
{
    public const string DB_ITEM_CLASS = AccountErasureResult::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountErasureResults::class;

    /**
     * @param int $assignmentId Assignment primary id
     * @return ?AccountErasureResult Saved result, or null before completion
     * @throws HilosException When the storage reader or item wrapper refuses the request
     */
    public function byAssignment(int $assignmentId): ?AccountErasureResult
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
     * @param ?ObjectAccountErasureResult $object Persisted object, or domain absence
     * @return ?AccountErasureResult Its wrapper, retaining already issued object identity
     * @throws InvalidArgumentException When the object type does not match
     * @throws LogicException When the collection class links are missing
     */
    private function itemFor(?ObjectAccountErasureResult $object): ?AccountErasureResult
    {
        if ($object === null || $object->id === null) {
            return null;
        }

        return $this->getOrCreateItemForLoadedObject($object->id, $object);
    }

    /**
     * @param ObjectAccountErasureResults $objects Ordered persisted selection
     * @return static Ordered manual selection of view items
     * @throws HilosException When a wrapper or manual selection rejects an item
     */
    private function selectionFor(ObjectAccountErasureResults $objects): static
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

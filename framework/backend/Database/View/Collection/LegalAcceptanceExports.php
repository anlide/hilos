<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Collection\LegalAcceptanceExports as ObjectLegalAcceptanceExports;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\LegalAcceptanceExport as ObjectLegalAcceptanceExport;
use Hilos\Database\View\Item\LegalAcceptanceExport;
use Hilos\HilosException;

/**
 * Array access uses the order id; ofUser() finds the administrator's one order.
 *
 * @extends DbCollection<LegalAcceptanceExport, ObjectLegalAcceptanceExports>
 * @method ObjectLegalAcceptanceExports|null getObjectCollection()
 * @method LegalAcceptanceExport|null first()
 * @method LegalAcceptanceExport|null last()
 * @method LegalAcceptanceExport offsetGet(mixed $offset)
 */
class LegalAcceptanceExports extends DbCollection
{
    public const string DB_ITEM_CLASS = LegalAcceptanceExport::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLegalAcceptanceExports::class;

    /**
     * @param int $userId Administrator whose order is sought
     * @return ?LegalAcceptanceExport Matching order, if any
     * @throws HilosException When the query or wrapper creation fails
     */
    public function ofUser(int $userId): ?LegalAcceptanceExport
    {
        return $this->itemFor($this->objectCollection->ofUser($userId));
    }

    /**
     * @return ?LegalAcceptanceExport Earliest unfinished order, if any
     * @throws HilosException When the query or wrapper creation fails
     */
    public function nextPreparing(): ?LegalAcceptanceExport
    {
        return $this->itemFor($this->objectCollection->nextPreparing());
    }

    /**
     * @param string $now Expiry boundary in SQL UTC
     * @return static Matching orders for the agent's file sweep
     * @throws HilosException When the query or wrapper creation fails
     * @throws ObjectGetIdStringNotImplementedException If DbItem's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects the item on a non-manual collection
     */
    public function expiredBy(string $now): static
    {
        return $this->itemsFor($this->objectCollection->expiredBy($now));
    }

    /**
     * @return static Ready orders for the agent's file sweep
     * @throws HilosException When the query or wrapper creation fails
     * @throws ObjectGetIdStringNotImplementedException If DbItem's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects the item on a non-manual collection
     */
    public function allReady(): static
    {
        return $this->itemsFor($this->objectCollection->allReady());
    }

    /**
     * @return static Ready and failed orders for the erasure of an account
     * @throws HilosException When the query or wrapper creation fails
     * @throws ObjectGetIdStringNotImplementedException If DbItem's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects the item on a non-manual collection
     */
    public function allFinished(): static
    {
        return $this->itemsFor($this->objectCollection->allFinished());
    }

    /**
     * @param ObjectLegalAcceptanceExports $exports Loaded orders
     * @return static Their view wrappers
     * @throws HilosException When the collection cannot wrap a row
     * @throws ObjectGetIdStringNotImplementedException If DbItem's Object does not implement getIdString()
     * @throws CollectionNotManualException When add() rejects the item on a non-manual collection
     */
    private function itemsFor(ObjectLegalAcceptanceExports $exports): static
    {
        $result = static::initEmpty();
        foreach ($exports as $object) {
            $item = $this->itemFor($object);
            if ($item !== null) {
                $result->add($item);
            }
        }

        return $result;
    }

    /**
     * @param ?ObjectLegalAcceptanceExport $object Loaded order
     * @return ?LegalAcceptanceExport Its view wrapper, if the row exists
     * @throws HilosException When the collection cannot wrap the row
     */
    private function itemFor(?ObjectLegalAcceptanceExport $object): ?LegalAcceptanceExport
    {
        $id = $object?->id;
        if ($object === null || $id === null) {
            return null;
        }

        return $this->getOrCreateItemForLoadedObject($id, $object);
    }
}

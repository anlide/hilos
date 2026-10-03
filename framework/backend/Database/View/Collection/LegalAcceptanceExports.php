<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\LegalAcceptanceExports as ObjectLegalAcceptanceExports;
use Hilos\Database\Object\Item\LegalAcceptanceExport as ObjectLegalAcceptanceExport;
use Hilos\Database\View\Item\LegalAcceptanceExport;
use Hilos\HilosException;

/**
 * Array access uses the order id; ofUser() finds the administrator's one order.
 *
 * @extends DbCollection<LegalAcceptanceExport, ObjectLegalAcceptanceExports>
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
     * @return list<LegalAcceptanceExport> Matching orders for the agent's file sweep
     * @throws HilosException When the query or wrapper creation fails
     */
    public function expiredBy(string $now): array
    {
        return $this->itemsFor($this->objectCollection->expiredBy($now));
    }

    /**
     * @return list<LegalAcceptanceExport> Ready orders for the agent's file sweep
     * @throws HilosException When the query or wrapper creation fails
     */
    public function allReady(): array
    {
        return $this->itemsFor($this->objectCollection->allReady());
    }

    /**
     * @return list<LegalAcceptanceExport> Ready and failed orders for the erasure of an account
     * @throws HilosException When the query or wrapper creation fails
     */
    public function allFinished(): array
    {
        return $this->itemsFor($this->objectCollection->allFinished());
    }

    /**
     * @param list<ObjectLegalAcceptanceExport> $objects Loaded orders
     * @return list<LegalAcceptanceExport> Their view wrappers
     * @throws HilosException When the collection cannot wrap a row
     */
    private function itemsFor(array $objects): array
    {
        $result = [];
        foreach ($objects as $object) {
            $item = $this->itemFor($object);
            if ($item !== null) {
                $result[] = $item;
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

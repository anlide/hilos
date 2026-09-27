<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\DataExports as ObjectDataExports;
use Hilos\Database\Object\Item\DataExport as ObjectDataExport;
use Hilos\Database\View\Item\DataExport;
use Hilos\HilosException;

/**
 * Array access uses the request id; ofUser() finds the person's one request.
 *
 * @extends DbCollection<DataExport, ObjectDataExports>
 */
class DataExports extends DbCollection
{
    public const string DB_ITEM_CLASS = DataExport::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectDataExports::class;

    /**
     * @param int $userId Person whose copy is sought
     * @return ?DataExport Matching request, if any
     * @throws HilosException When the query or wrapper creation fails
     */
    public function ofUser(int $userId): ?DataExport
    {
        return $this->itemFor($this->objectCollection->ofUser($userId));
    }

    /**
     * @return ?DataExport Matching request, if any
     * @throws HilosException When the query or wrapper creation fails
     */
    public function nextPreparing(): ?DataExport
    {
        return $this->itemFor($this->objectCollection->nextPreparing());
    }

    /**
     * @param string $now Expiry boundary in SQL UTC
     * @return list<DataExport> Matching queue rows for the agent's file sweep
     * @throws HilosException When the query or wrapper creation fails
     */
    public function expiredBy(string $now): array
    {
        $result = [];
        foreach ($this->objectCollection->expiredBy($now) as $object) {
            $item = $this->itemFor($object);
            if ($item !== null) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @return list<DataExport> Matching queue rows for the agent's file sweep
     * @throws HilosException When the query or wrapper creation fails
     */
    public function allReady(): array
    {
        $result = [];
        foreach ($this->objectCollection->allReady() as $object) {
            $item = $this->itemFor($object);
            if ($item !== null) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param ?ObjectDataExport $object Loaded queue row
     * @return ?DataExport Its view wrapper, if the row exists
     * @throws HilosException When the collection cannot wrap the row
     */
    private function itemFor(?ObjectDataExport $object): ?DataExport
    {
        $id = $object?->id;
        if ($object === null || $id === null) {
            return null;
        }

        return $this->getOrCreateItemForLoadedObject($id, $object);
    }
}

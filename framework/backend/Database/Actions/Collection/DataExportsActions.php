<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\DataExports as ObjectDataExports;
use Hilos\Database\View\Collection\DataExports as DbCollectionDataExports;
use Hilos\Database\View\Item\DataExport;
use Hilos\HilosException;
use Throwable;

/**
 * Replaces a person's previous request atomically; archive cleanup belongs to the agent.
 *
 * @extends DbActions<DataExport, ObjectDataExports>
 * @property-read DbCollectionDataExports $collection
 * @property-read ObjectDataExports $objectCollection
 */
class DataExportsActions extends DbActions
{
    /**
     * @param int $userId Person requesting a copy
     * @param string $requestedAt Request time in SQL UTC
     * @return DataExport New preparing request
     * @throws HilosException When ownership, transaction, insertion, or wrapping fails
     */
    public function order(int $userId, string $requestedAt): DataExport
    {
        $this->ensureCanCreateInSet((string)$userId);
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        Database::transactionStart();
        try {
            $this->collection->ofUser($userId)?->actions->delete();
            $request = $this->createDbItemFromObject($this->objectCollection->order($userId, $requestedAt));
            Database::transactionCommit();

            return $request;
        } catch (Throwable $e) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Preserve the failure that prevented the request from being replaced.
            }
            $this->objectCollection->clearInMemory();
            $this->clearCollectionCache();
            throw $e;
        }
    }

    /**
     * @param int $userId Person being forgotten, whose request id is not known to the caller
     * @throws HilosException When lookup, ownership, or deletion fails
     */
    public function deleteForUser(int $userId): void
    {
        $this->collection->ofUser($userId)?->actions->delete();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\LegalAcceptanceExports as ObjectLegalAcceptanceExports;
use Hilos\Database\View\Collection\LegalAcceptanceExports as DbCollectionLegalAcceptanceExports;
use Hilos\Database\View\Item\LegalAcceptanceExport;
use Hilos\HilosException;
use Throwable;

/**
 * Replaces an administrator's previous order atomically; file cleanup belongs to the agent.
 *
 * @extends DbActions<LegalAcceptanceExport, ObjectLegalAcceptanceExports>
 * @property-read DbCollectionLegalAcceptanceExports $collection
 * @property-read ObjectLegalAcceptanceExports $objectCollection
 */
class LegalAcceptanceExportsActions extends DbActions
{
    /**
     * @param int $userId Administrator placing the order
     * @param ?string $document Document filter of the order, or null for every document
     * @param ?string $revisionId Revision filter of the order, or null for every revision
     * @param ?string $search Search of the order, or null for none
     * @param string $requestedAt Request time in SQL UTC
     * @return LegalAcceptanceExport New preparing order
     * @throws HilosException When ownership, transaction, insertion, or wrapping fails
     */
    public function order(int $userId, ?string $document, ?string $revisionId, ?string $search, string $requestedAt): LegalAcceptanceExport
    {
        $this->ensureCanCreateInSet((string)$userId);
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        Database::transactionStart();
        try {
            $this->collection->ofUser($userId)?->actions->delete();
            $order = $this->createDbItemFromObject(
                $this->objectCollection->order($userId, $document, $revisionId, $search, $requestedAt),
            );
            Database::transactionCommit();

            return $order;
        } catch (Throwable $e) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Preserve the failure that prevented the order from being replaced.
            }
            throw $e;
        }
    }
}

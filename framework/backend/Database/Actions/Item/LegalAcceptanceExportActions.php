<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Item\LegalAcceptanceExport as ObjectLegalAcceptanceExport;
use Hilos\Database\View\Item\LegalAcceptanceExport;
use Hilos\HilosException;

/**
 * Completion and deletion of one loaded order of acceptance records.
 *
 * @extends DbActions<LegalAcceptanceExport, ObjectLegalAcceptanceExport>
 * @property-read ObjectLegalAcceptanceExport $object
 */
class LegalAcceptanceExportActions extends DbActions
{
    /**
     * @param string $storedName Finished file basename
     * @param int $sizeBytes File size
     * @param int $records Acceptance records written
     * @param string $finishedAt Completion time in SQL UTC
     * @param string $expiresAt Expiry time in SQL UTC
     * @return bool Whether this call completed the still-preparing order
     * @throws HilosException When ownership, storage, or announcement fails
     */
    public function finishReady(string $storedName, int $sizeBytes, int $records, string $finishedAt, string $expiresAt): bool
    {
        $this->ensureCanWrite();

        return $this->object->finishReady($storedName, $sizeBytes, $records, $finishedAt, $expiresAt);
    }

    /**
     * @param string $finishedAt Failure time in SQL UTC
     * @param string $expiresAt Expiry time in SQL UTC
     * @return bool Whether this call ended the still-preparing order
     * @throws HilosException When ownership, storage, or announcement fails
     */
    public function finishFailed(string $finishedAt, string $expiresAt): bool
    {
        $this->ensureCanWrite();

        return $this->object->finishFailed($finishedAt, $expiresAt);
    }

    /**
     * Removes this order row; its file is the agent's responsibility.
     *
     * @throws HilosException When ownership, deletion, or announcement fails
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $collection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Legal acceptances export is detached from its collection');
        $id = $this->object->getIdString();
        $this->object->delete();
        unset($collection[$id]);
    }
}

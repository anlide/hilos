<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Item\DataExport as ObjectDataExport;
use Hilos\Database\View\Item\DataExport;
use Hilos\HilosException;

/**
 * Completion and deletion of one loaded request.
 *
 * @extends DbActions<DataExport, ObjectDataExport>
 * @property-read ObjectDataExport $object
 */
class DataExportActions extends DbActions
{
    /**
     * @param string $storedName Finished archive basename
     * @param int $sizeBytes Archive size
     * @param int $finishedAt Completion moment in epoch milliseconds
     * @param int $expiresAt Expiry moment in epoch milliseconds
     * @param string $timeZone IANA name of the time zone the archive was written in
     * @return bool Whether this call completed the still-preparing request
     * @throws HilosException When ownership, storage, or announcement fails
     */
    public function finishReady(string $storedName, int $sizeBytes, int $finishedAt, int $expiresAt, string $timeZone): bool
    {
        $this->ensureCanWrite();

        return $this->object->finishReady($storedName, $sizeBytes, $finishedAt, $expiresAt, $timeZone);
    }

    /**
     * @param int $finishedAt Failure moment in epoch milliseconds
     * @param int $expiresAt Expiry moment in epoch milliseconds
     * @return bool Whether this call ended the still-preparing request
     * @throws HilosException When ownership, storage, or announcement fails
     */
    public function finishFailed(int $finishedAt, int $expiresAt): bool
    {
        $this->ensureCanWrite();

        return $this->object->finishFailed($finishedAt, $expiresAt);
    }

    /**
     * Removes this queue row; its archive file is the agent's responsibility.
     *
     * @throws HilosException When ownership, deletion, or announcement fails
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $collection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Data export is detached from its collection');
        $id = $this->object->getIdString();
        $this->object->delete();
        unset($collection[$id]);
    }
}

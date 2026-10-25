<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\ExternalWriterException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\ChangeLogReceipts as ObjectChangeLogReceipts;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\ChangeLogReceipts as DbCollectionChangeLogReceipts;
use Hilos\Database\View\Item\ChangeLogReceipt;

/**
 * Writes one journal receipt through the owning object collection.
 *
 * @extends DbActions<ChangeLogReceipt, ObjectChangeLogReceipts>
 * @property-read DbCollectionChangeLogReceipts $collection
 * @property-read ObjectChangeLogReceipts $objectCollection
 */
class ChangeLogReceiptsActions extends DbActions
{
    /**
     * Inserts one receipt and returns the view of the row the object insert already read.
     *
     * @param JournalReceiptData $data Attribution stored on the receipt
     * @param bool $retainLocal Whether the insert keeps one local handover
     * @return ChangeLogReceipt View of the receipt the insert stored
     * @throws CreateNotAllowedException When no truth source in this process may add a receipt
     * @throws ExternalWriterException When the mounted Entity declares an external database writer
     * @throws WriteNotAllowedException When the receipt table is not application-writable
     * @throws DatabaseException When the binding, insert, or stored-row read fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws ObjectGetIdStringNotImplementedException When the stored row has no id
     * @throws CallbackNotSetException When the view factory callback is not set
     */
    public function createReceipt(JournalReceiptData $data, bool $retainLocal = false): ChangeLogReceipt
    {
        $this->ensureCanCreate();

        return $this->createDbItemFromObject($this->objectCollection->insertReceipt($data, $retainLocal));
    }
}

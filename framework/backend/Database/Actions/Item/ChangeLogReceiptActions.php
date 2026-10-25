<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\ChangeLogReceipt as ObjectChangeLogReceipt;
use Hilos\Database\View\Item\ChangeLogReceipt;

/**
 * Handover and cleanup writes for one journal receipt.
 *
 * @extends DbActions<ChangeLogReceipt, ObjectChangeLogReceipt>
 * @property-read ObjectChangeLogReceipt $object
 */
class ChangeLogReceiptActions extends DbActions
{
    /**
     * Asks the update right, then adds a delta to the durable handover counter.
     *
     * @param int $delta Handovers to add, negative to release
     * @throws ObjectCollectionNullException When the receipt is detached from its object collection
     * @throws DatabaseException When the update, the refresh or the announcement fails
     * @throws ObjectGetIdStringNotImplementedException When the persisted row has no id
     * @throws WriteNotAllowedException When no truth source in this process may update the row
     * @throws CreateNotAllowedException When the row is not stored yet and creating it is not allowed
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function adjustHandovers(int $delta): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);
        $this->object->adjustHandovers($delta);
    }

    /**
     * Asks the update right, then applies one part's net delta and optional writer.
     *
     * @param int $delta Handovers to add, negative when a hold is released
     * @param ?int $entriesBefore Journal rows seen before the part, or null when the count failed
     * @param string $agentId Agent that ran the part
     * @throws ObjectCollectionNullException When the receipt is detached from its object collection
     * @throws DatabaseException When the update, the refresh or the announcement fails
     * @throws ObjectGetIdStringNotImplementedException When the persisted row has no id
     * @throws WriteNotAllowedException When no truth source in this process may update the row
     * @throws CreateNotAllowedException When the row is not stored yet and creating it is not allowed
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function concludePart(int $delta, ?int $entriesBefore, string $agentId): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);
        $this->object->concludePart($delta, $entriesBefore, $agentId);
    }

    /**
     * Asks the remove right, then deletes this receipt when it holds nothing.
     *
     * @throws ObjectCollectionNullException When the receipt is detached from its object collection
     * @throws DatabaseException When the delete or its announcement fails
     * @throws ObjectGetIdStringNotImplementedException When the persisted row has no id
     * @throws WriteNotAllowedException When no truth source in this process may remove the row
     * @throws CreateNotAllowedException When the row is not stored yet and creating it is not allowed
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function deleteIfEmpty(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $this->object->deleteIfEmpty();
    }

    /**
     * Asks the remove right, then deletes an empty migration receipt.
     *
     * A channel other than migration is refused by the object item. This door does not
     * become a way to ignore holders.
     *
     * @throws ObjectCollectionNullException When the receipt is detached from its object collection
     * @throws LogicException When the receipt channel is not migration
     * @throws DatabaseException When the delete or its announcement fails
     * @throws ObjectGetIdStringNotImplementedException When the persisted row has no id
     * @throws WriteNotAllowedException When no truth source in this process may remove the row
     * @throws CreateNotAllowedException When the row is not stored yet and creating it is not allowed
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     */
    public function deleteEmptyMigrationReceipt(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $this->object->deleteEmptyMigrationReceipt();
    }
}

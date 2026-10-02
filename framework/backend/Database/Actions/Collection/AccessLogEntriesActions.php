<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Auth\AccessLog\AccessLogEvent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\AccessLogEntries as ObjectAccessLogEntries;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\AccessLogEntries as DbCollectionAccessLogEntries;
use Hilos\Database\View\Item\AccessLogEntry;

/**
 * AccessLogEntriesActions - writes of the account access log (HIL-1174).
 *
 * A row is written once and never edited: it is added on a use of the account, moved to the
 * survivor of a merge, and removed with the person or once past its life.
 *
 * @extends DbActions<AccessLogEntry, ObjectAccessLogEntries>
 * @property-read DbCollectionAccessLogEntries $collection
 * @property-read ObjectAccessLogEntries $objectCollection
 */
class AccessLogEntriesActions extends DbActions
{
    /**
     * Records one use of a person's account.
     *
     * @param int $userId Person whose account was used
     * @param AccessLogEvent $event What the use was
     * @param ?string $ipAddress Network address of the use, or null when the transport gave none
     * @param string $occurredAtSql Moment of the use (SQL datetime)
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws WriteNotAllowedException When the truth source rejects the row write
     * @throws DatabaseException When the write fails
     * @throws InvalidArgumentException When the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function record(int $userId, AccessLogEvent $event, ?string $ipAddress, string $occurredAtSql): void
    {
        $this->ensureCanCreateInSet((string)$userId);

        $this->objectCollection->record($userId, $event, $ipAddress, $occurredAtSql);
    }

    /**
     * Deletes every access log row of a person - the account is being erased (HIL-1174).
     *
     * @param int $userId Person
     * @throws WriteNotAllowedException When the truth source rejects the delete
     * @throws DatabaseException When the lookup or a delete fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);

        $this->objectCollection->deleteForUser($userId);
    }

    /**
     * Deletes up to a limit of rows written before a moment, oldest row first - the sweep of the log.
     *
     * The rows belong to many people, so the right asked is the one over the whole table.
     *
     * @param string $beforeSql Rows that occurred strictly before this moment go (SQL datetime)
     * @param int $limit Maximum rows to delete
     * @return int Number of rows deleted
     * @throws WriteNotAllowedException When the truth source rejects the delete
     * @throws DatabaseException When the lookup or a delete fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteOlderThan(string $beforeSql, int $limit): int
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        return $this->objectCollection->deleteOlderThan($beforeSql, $limit);
    }

    /**
     * Re-points every access log row of a merged loser to the survivor (HIL-1174).
     *
     * @param int $fromUserId Loser user id whose rows are absorbed
     * @param int $toUserId Survivor user id that receives the rows
     * @return int Number of rows re-pointed to the survivor
     * @throws WriteNotAllowedException When the truth source rejects the move
     * @throws CreateNotAllowedException When the truth source rejects a row landing in the survivor's set
     * @throws DatabaseException When the lookup or a move fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException When a row's id cannot be named for the write
     */
    public function rePointToUser(int $fromUserId, int $toUserId): int
    {
        $this->ensureCanWriteSet((string)$fromUserId, TruthSourceOperation::Update);
        $this->ensureCanCreateInSet((string)$toUserId);

        return $this->objectCollection->rePointToUser($fromUserId, $toUserId);
    }
}

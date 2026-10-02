<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\AccessLog\AccessLogEvent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\AccessLogEntries as EntityAccessLogEntries;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\AccessLogEntry as EntityAccessLogEntry;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\AccessLogEntry as ObjectAccessLogEntry;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;

/**
 * AccessLogEntries object collection - the account access log of every person (HIL-1174).
 *
 * @extends Objects<ObjectAccessLogEntry>
 * @method ObjectAccessLogEntry|null current()
 * @method ObjectAccessLogEntry|null first()
 * @method ObjectAccessLogEntry|null last()
 * @method ObjectAccessLogEntry|null get(int|string $key)
 * @method ObjectAccessLogEntry|null offsetGet(mixed $offset)
 */
class AccessLogEntries extends Objects
{
    public const string OBJECT_CLASS = ObjectAccessLogEntry::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccessLogEntries::class;
    public const string COLLECTION_KEY = HilosDbContext::accessLogEntries;

    /**
     * Records one use of a person's account.
     *
     * @param int $userId Person whose account was used
     * @param AccessLogEvent $event What the use was
     * @param ?string $ipAddress Network address of the use, or null when the transport gave none
     * @param string $occurredAtSql Moment of the use (SQL datetime)
     * @throws DatabaseException When the write fails
     * @throws InvalidArgumentException When the queued DB-sync signal is invalid
     * @throws CreateNotAllowedException When no truth source may add an access log row
     * @throws WriteNotAllowedException When no truth source may write the row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function record(int $userId, AccessLogEvent $event, ?string $ipAddress, string $occurredAtSql): void
    {
        $entry = static::OBJECT_CLASS::create();
        $entry->userId = $userId;
        $entry->event = $event->value;
        $entry->ipAddress = $ipAddress;
        $entry->occurredAt = $occurredAtSql;
        $entry->sync();

        if ($entry->id !== null) {
            $this[$entry->id] = $entry;
        }
    }

    /**
     * Deletes every access log row of a person - the account is being erased (HIL-1174).
     *
     * Each row leaves through its object so a delete announcement reaches every reader.
     * A person with none is not an error.
     *
     * @param int $userId Person whose rows to delete
     * @throws DatabaseException When the lookup or a delete fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        foreach ($this->hydrateAll(static::entityClass()::get([EntityAccessLogEntry::user_id => $userId])) as $entry) {
            $this->remove($entry);
        }
    }

    /**
     * Re-points every access log row of a merged loser to the survivor (HIL-1174).
     *
     * The log travels with the account as its device keys do. A person with none is not an error.
     * Each row moves through its object so an update announcement reaches every reader.
     *
     * @param int $fromUserId Loser user id whose rows are absorbed
     * @param int $toUserId Survivor user id that receives the rows
     * @return int Number of rows re-pointed to the survivor
     * @throws DatabaseException When the lookup or a move fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws ObjectGetIdStringNotImplementedException When the row's id cannot be named for the write
     */
    public function rePointToUser(int $fromUserId, int $toUserId): int
    {
        $moved = 0;
        foreach ($this->hydrateAll(static::entityClass()::get([EntityAccessLogEntry::user_id => $fromUserId])) as $entry) {
            $entry->userId = $toUserId;
            $entry->sync();
            $moved++;
        }

        return $moved;
    }

    /**
     * Deletes up to a limit of rows written before a moment, oldest row first.
     *
     * Each row leaves through its object so a delete announcement reaches every reader.
     *
     * @param string $beforeSql Rows that occurred strictly before this moment go (SQL datetime)
     * @param int $limit Maximum rows to delete
     * @return int Number of rows deleted
     * @throws DatabaseException When the lookup or a delete fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteOlderThan(string $beforeSql, int $limit): int
    {
        $entries = $this->hydrateAll(static::entityClass()::get(
            '`' . EntityAccessLogEntry::occurred_at . '` < ?',
            [$beforeSql],
            [EntityAccessLogEntry::id => SqlSortDirection::ASC],
            $limit,
        ));
        foreach ($entries as $entry) {
            $this->remove($entry);
        }

        return count($entries);
    }

    /**
     * Lists every access log row of a person in the order they occurred.
     *
     * @param int $userId Person whose rows to list
     * @return list<ObjectAccessLogEntry> Row objects, oldest first, empty when none
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function ofUser(int $userId): array
    {
        return $this->hydrateAll(static::entityClass()::get(
            [EntityAccessLogEntry::user_id => $userId],
            [],
            [EntityAccessLogEntry::occurred_at => SqlSortDirection::ASC, EntityAccessLogEntry::id => SqlSortDirection::ASC],
        ));
    }

    /**
     * Deletes one row through its object and drops it from memory.
     *
     * @param ObjectAccessLogEntry $entry Row to delete, whose id is known to be set
     * @throws DatabaseException When the delete fails
     * @throws InvalidArgumentException When the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    private function remove(ObjectAccessLogEntry $entry): void
    {
        $id = $entry->id;
        $entry->delete();
        unset($this[$id]);
    }

    /**
     * Loads every row of an entity query result, reusing the object already standing for a row.
     *
     * Typed on the base collection: the string-filter form of `Entity::get()` hands back a plain
     * {@see EntityCollection}.
     *
     * @param EntityCollection<EntityAccessLogEntry> $entities Entity query result to wrap
     * @return list<ObjectAccessLogEntry> Row objects (empty when nothing matched)
     */
    private function hydrateAll(EntityCollection $entities): array
    {
        $result = [];
        foreach ($entities as $entity) {
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $result[] = $this->objects[$id];
        }

        return $result;
    }
}

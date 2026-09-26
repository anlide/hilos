<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\EventUserRename;
use Demo\Chat\Database\Object\Collection\EventUserRenames as ObjectEventUserRenames;
use Demo\Chat\Database\Object\Item\EventUserRename as ObjectEventUserRename;
use Demo\Chat\Database\View\Collection\EventUserRenames as DbCollectionEventUserRenames;
use Demo\Chat\Database\View\Item\EventUserRename as DbEventUserRename;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;

/**
 * EventUserRenamesActions - write operations for rename event details.
 *
 * @extends DbActions<DbEventUserRename, ObjectEventUserRenames>
 * @property-read DbCollectionEventUserRenames $collection
 * @property-read ObjectEventUserRenames $objectCollection
 */
final class EventUserRenamesActions extends DbActions
{
    /**
     * Get table name for EventUserRenames collection.
     *
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return EventUserRename::_table;
    }

    /**
     * Creates scalar detail for one user rename event.
     *
     * @param int $eventId Parent event id
     * @param int $targetUserId Renamed user id
     * @param ?int $actorUserId User who initiated the rename, when known
     * @param string $oldName Previous display name
     * @param string $newName New display name
     * @return DbEventUserRename Created rename detail
     * @throws HilosException On database or truth-source failure
     */
    public function create(
        int $eventId,
        int $targetUserId,
        ?int $actorUserId,
        string $oldName,
        string $newName,
    ): DbEventUserRename
    {
        TruthSourceRegistry::checkCanCreate(ChatDbContext::eventUserRenames);
        $this->ensureCanWrite(TruthSourceOperation::Add);

        $detail = ObjectEventUserRename::create();
        $detail->eventId = $eventId;
        $detail->targetUserId = $targetUserId;
        $detail->actorUserId = $actorUserId;
        $detail->oldName = $oldName;
        $detail->newName = $newName;
        $detail->sync();

        $this->addObjectToCollection($detail);

        return $this->createDbItemFromObject($detail);
    }

    /**
     * Deletes every rename detail row and clears the collection cache.
     *
     * @throws HilosException On database or truth-source failure
     */
    public function deleteAll(): void
    {
        TruthSourceRegistry::checkCanWrite(ChatDbContext::eventUserRenames, TruthSourceOperation::Remove);

        $this->deleteAllObjects();
    }

    /**
     * Deletes the rename event details of a person - the account is being erased (HIL-302).
     *
     * @param int $userId Renamed user id
     * @return list<int> Event ids whose details went; the events themselves are the caller's to delete
     * @throws HilosException On database or truth-source failure
     */
    public function deleteByTarget(int $userId): array
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        $eventIds = [];
        foreach (EventUserRename::get([EventUserRename::target_user_id => $userId]) as $entity) {
            $eventId = $entity->event_id;
            $rename = $this->objectCollection[$eventId] ?? ObjectEventUserRename::fromEntity($entity);
            $rename->delete();
            unset($this->objectCollection[$eventId]);
            $eventIds[] = $eventId;
        }

        return $eventIds;
    }

    /**
     * Takes a person off the renames of others they made - the account is being erased (HIL-302).
     *
     * The rename stays: it happened to somebody else, and only the mention of who did it goes.
     *
     * @param int $userId Actor user id
     * @return int Number of renames that lost their actor
     * @throws HilosException On database or truth-source failure
     */
    public function clearActor(int $userId): int
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);

        $cleared = 0;
        foreach (EventUserRename::get([EventUserRename::actor_user_id => $userId]) as $entity) {
            $eventId = $entity->event_id;
            $rename = $this->objectCollection[$eventId] ?? ObjectEventUserRename::fromEntity($entity);
            $this->objectCollection[$eventId] = $rename;
            $rename->actorUserId = null;
            $rename->sync();
            $cleared++;
        }

        return $cleared;
    }
}

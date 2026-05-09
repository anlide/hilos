<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Item\EventUserRename;
use Demo\Chat\Database\Object\Collection\EventUserRenames as ObjectEventUserRenames;
use Demo\Chat\Database\Object\Item\EventUserRename as ObjectEventUserRename;
use Demo\Chat\Database\View\Collection\EventUserRenames as DbCollectionEventUserRenames;
use Demo\Chat\Database\View\Item\EventUserRename as DbEventUserRename;
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
        TruthSourceRegistry::checkCanCreate(DbChatContext::eventUserRenames);
        $this->ensureCanWrite();

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
        TruthSourceRegistry::checkCanWrite(DbChatContext::eventUserRenames);
        $this->ensureCanWrite();

        $this->objectCollection->deleteAll();

        $this->clearCollectionCache();
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\EventUserRegistration;
use Demo\Chat\Database\Object\Collection\EventUserRegistrations as ObjectEventUserRegistrations;
use Demo\Chat\Database\Object\Item\EventUserRegistration as ObjectEventUserRegistration;
use Demo\Chat\Database\View\Collection\EventUserRegistrations as DbCollectionEventUserRegistrations;
use Demo\Chat\Database\View\Item\EventUserRegistration as DbEventUserRegistration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;

/**
 * EventUserRegistrationsActions - write operations for registration event details.
 *
 * @extends DbActions<DbEventUserRegistration, ObjectEventUserRegistrations>
 * @property-read DbCollectionEventUserRegistrations $collection
 * @property-read ObjectEventUserRegistrations $objectCollection
 */
final class EventUserRegistrationsActions extends DbActions
{
    /**
     * Get table name for EventUserRegistrations collection.
     *
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return EventUserRegistration::_table;
    }

    /**
     * Creates scalar detail for one user registration event.
     *
     * @param int $eventId Parent event id
     * @param int $targetUserId Registered user id
     * @return DbEventUserRegistration Created registration detail
     * @throws HilosException On database or truth-source failure
     */
    public function create(int $eventId, int $targetUserId): DbEventUserRegistration
    {
        TruthSourceRegistry::checkCanCreate(ChatDbContext::eventUserRegistrations);
        $this->ensureCanWrite();

        $detail = ObjectEventUserRegistration::create();
        $detail->eventId = $eventId;
        $detail->targetUserId = $targetUserId;
        $detail->sync();

        $this->addObjectToCollection($detail);

        return $this->createDbItemFromObject($detail);
    }

    /**
     * Deletes every registration detail row and clears the collection cache.
     *
     * @throws HilosException On database or truth-source failure
     */
    public function deleteAll(): void
    {
        TruthSourceRegistry::checkCanWrite(ChatDbContext::eventUserRegistrations);
        $this->ensureCanWrite();

        $this->objectCollection->deleteAll();

        $this->clearCollectionCache();
    }
}

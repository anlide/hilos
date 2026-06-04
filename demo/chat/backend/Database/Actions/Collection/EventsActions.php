<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Database\DTO\PublishedAttachmentInputs;
use Demo\Chat\Database\Entity\Item\Event;
use Demo\Chat\Database\View\Collection\Events as DbCollectionEvents;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Database\View\Item\Event as DbEvent;
use Demo\Chat\Hilos;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * EventsActions - write operations for Events collection.
 *
 * @extends DbActions<DbEvent, ObjectEvents>
 * @property-read DbCollectionEvents $collection
 * @property-read ObjectEvents $objectCollection
 */
final class EventsActions extends DbActions
{
    /**
     * Get table name for Events collection.
     *
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return Event::_table;
    }

    /**
     * Appends the system event that marks chat startup.
     *
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws LogicException If event id is null after sync
     */
    public function addChatStarted(): DbEvent
    {
        return $this->add(ChatEventType::CHAT_STARTED->value);
    }

    /**
     * Appends the system event that marks chat shutdown.
     *
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws LogicException If event id is null after sync
     */
    public function addChatStopped(): DbEvent
    {
        return $this->add(ChatEventType::CHAT_STOPPED->value);
    }

    /**
     * Appends the system event that marks chat history cleanup.
     *
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws LogicException If event id is null after sync
     */
    public function addChatCleared(): DbEvent
    {
        return $this->add(ChatEventType::CHAT_CLEARED->value);
    }

    /**
     * Appends the event emitted when a user registers in chat.
     *
     * @param int $userId Registered user id
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws LogicException If event id is null after sync
     */
    public function addUserRegistered(int $userId): DbEvent
    {
        $event = $this->add(ChatEventType::USER_REGISTERED->value);
        Hilos::$db->eventUserRegistrations->actions->create((int)$event->id, $userId);

        return $event;
    }

    /**
     * Appends the event emitted when a user renames themselves.
     *
     * @param int $userId Renamed user id
     * @param string $oldName Previous display name
     * @param string $newName New display name
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws LogicException If event id is null after sync
     */
    public function addUserRenamed(int $userId, string $oldName, string $newName): DbEvent
    {
        $event = $this->add(ChatEventType::USER_RENAMED->value);
        Hilos::$db->eventUserRenames->actions->create(
            eventId: (int)$event->id,
            targetUserId: $userId,
            actorUserId: $userId,
            oldName: $oldName,
            newName: $newName,
        );

        return $event;
    }

    /**
     * Appends the event emitted when an admin renames a user.
     *
     * @param int $userId Renamed user id
     * @param string $oldName Previous display name
     * @param string $newName New display name
     * @param ?int $adminUserId Initiator user id, when known
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws LogicException If event id is null after sync
     */
    public function addUserRenamedByAdmin(
        int $userId,
        string $oldName,
        string $newName,
        ?int $adminUserId = null,
    ): DbEvent
    {
        $event = $this->add(ChatEventType::USER_RENAMED_BY_ADMIN->value);
        Hilos::$db->eventUserRenames->actions->create(
            eventId: (int)$event->id,
            targetUserId: $userId,
            actorUserId: $adminUserId,
            oldName: $oldName,
            newName: $newName,
        );

        return $event;
    }

    /**
     * Appends the event emitted when a chat message is published.
     *
     * Exactly one of `$userId` or `$botId` is expected from the caller.
     *
     * @param string $message Published message text
     * @param ?int $userId Authoring user id
     * @param ?int $botId Authoring bot id
     * @param ?PublishedAttachmentInputs $attachments Published attachment metadata
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws LogicException If event id is null after sync
     */
    public function addMessage(
        string $message,
        ?int $userId = null,
        ?int $botId = null,
        ?PublishedAttachmentInputs $attachments = null,
    ): DbEvent
    {
        $event = $this->add(ChatEventType::MESSAGE_SENT->value);
        Hilos::$db->eventMessages->actions->create(
            eventId: (int)$event->id,
            authorUserId: $userId,
            authorBotId: $botId,
            message: $message,
        );

        if ($attachments !== null) {
            foreach ($attachments as $attachment) {
                Hilos::$db->eventAttachments->actions->create(
                    (int)$event->id,
                    $attachment->filename,
                    $attachment->mimeType,
                    $attachment->storedName,
                );
            }
        }

        return $event;
    }

    /**
     * Adds a new event to the collection and persists it to the database.
     *
     * @param string $type Event type
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws LogicException If event id is null after sync
     */
    private function add(string $type): DbEvent
    {
        $this->ensureCanWrite();

        $objectEvent = ObjectEvent::create();
        $objectEvent->type = $type;
        $objectEvent->timestamp = TimeHelper::getSqlDateTime();
        $objectEvent->sync();

        if ($objectEvent->id === null) {
            throw new LogicException("Failed to save event to database: id is null after sync");
        }

        $this->addObjectToCollection($objectEvent);
        return $this->createDbItemFromObject($objectEvent);
    }

    /**
     * Deletes all events from database and clears the collection.
     *
     * @throws HilosException On error (permission error, database error, etc.)
     */
    public function deleteAll(): void
    {
        $this->ensureCanWrite();

        Hilos::$db->eventAttachments->actions->deleteAll();
        Hilos::$db->eventMessages->actions->deleteAll();
        Hilos::$db->eventUserRegistrations->actions->deleteAll();
        Hilos::$db->eventUserRenames->actions->deleteAll();
        $this->objectCollection->deleteAll();

        $this->clearCollectionCache();
    }
}

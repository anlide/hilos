<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Database\Entity\Item\Event;
use Demo\Chat\Database\View\Collection\Events as DbCollectionEvents;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Database\View\Item\Event as DbEvent;
use Demo\Chat\Hilos;
use Demo\Chat\Notification\ChatMentionNotifier;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Database;
use Hilos\Database\View\Item\UserRename;
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
     * Appends the feed line alone; the journal row's link is the person's agent's (HIL-1896).
     *
     * The type follows from the row's author: a person who renamed themselves - an administrator
     * renaming their own account included - is 'user_renamed', anybody else or nobody is
     * 'user_renamed_by_admin'.
     *
     * @param UserRename $rename Journal row of the rename just committed
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws LogicException If event id is null after sync
     */
    public function addUserRenamed(UserRename $rename): DbEvent
    {
        return $this->add(
            $rename->renamedByUserId === $rename->userId
                ? ChatEventType::USER_RENAMED->value
                : ChatEventType::USER_RENAMED_BY_ADMIN->value,
        );
    }

    /**
     * Appends the event emitted when a chat message is published.
     *
     * Exactly one of `$userId` or `$botId` is expected from the caller.
     *
     * @param string $message Published message text
     * @param ?int $userId Authoring user id
     * @param ?int $botId Authoring bot id
     * @param list<int> $fileIds Registry files sent with the message, in the order they were attached
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws LogicException If event id is null after sync
     */
    public function addMessage(
        string $message,
        ?int $userId = null,
        ?int $botId = null,
        array $fileIds = [],
    ): DbEvent
    {
        $event = $this->add(ChatEventType::MESSAGE_SENT->value);
        Hilos::$db->eventMessages->actions->create(
            eventId: (int)$event->id,
            authorUserId: $userId,
            authorBotId: $botId,
            message: $message,
        );

        // A mention must not escape a transaction whose later attachment write rolls back.
        Database::afterCommit(static fn () => ChatMentionNotifier::notifyMentions((int)$event->id, $message, $userId, $botId));

        foreach ($fileIds as $fileId) {
            Hilos::$db->eventAttachments->actions->create((int)$event->id, $fileId);
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
        $this->ensureCanCreate();

        $objectEvent = ObjectEvent::create();
        $objectEvent->type = $type;
        $objectEvent->timestamp = TimeHelper::nowMs();
        $objectEvent->sync();

        if ($objectEvent->id === null) {
            throw new LogicException("Failed to save event to database: id is null after sync");
        }

        $this->addObjectToCollection($objectEvent);
        return $this->createDbItemFromObject($objectEvent);
    }

    /**
     * Deletes all events from database and clears the collection.
     * The journal rows were taken off their events by their owners before (HIL-1897);
     * a link written after that is cleared by the key's ON DELETE SET NULL.
     *
     * @throws HilosException On error (permission error, database error, etc.)
     */
    public function deleteAll(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        Hilos::$db->eventAttachments->actions->deleteAll();
        Hilos::$db->eventMessages->actions->deleteAll();
        Hilos::$db->eventUserRegistrations->actions->deleteAll();

        $this->deleteAllObjects();
    }

    /**
     * Deletes the given events - the account they were about is being erased (HIL-302).
     *
     * Their detail rows go first, by the caller: a detail restricts nothing here, but deleting
     * it through its own collection is what lets the readers of that collection hear it.
     *
     * @param list<int> $eventIds Event ids to delete
     * @return int Number of events deleted
     * @throws HilosException On database or truth-source failure
     */
    public function deleteByIds(array $eventIds): int
    {
        if ($eventIds === []) {
            return 0;
        }

        $this->ensureCanWrite(TruthSourceOperation::Remove);

        $where = '`' . Event::id . '` IN (' . implode(', ', array_fill(0, count($eventIds), '?')) . ')';
        $deleted = 0;
        foreach (Event::get($where, $eventIds) as $entityEvent) {
            $id = $entityEvent->id;
            if ($id === null) {
                continue;
            }
            $event = $this->objectCollection[$id] ?? ObjectEvent::fromEntity($entityEvent);
            $event->delete();
            unset($this->objectCollection[$id]);
            $deleted++;
        }

        return $deleted;
    }
}

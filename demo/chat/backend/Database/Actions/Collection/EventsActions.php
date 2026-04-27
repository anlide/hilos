<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Database\Entity\Item\Event;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Demo\Chat\Database\View\Collection\Events as DbCollectionEvents;
use Demo\Chat\Database\View\Item\Event as DbEvent;
use Hilos\Core\CLI\Exception\CommandException;
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
     * @throws CommandException If event id is null after sync
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
     * @throws CommandException If event id is null after sync
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
     * @throws CommandException If event id is null after sync
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
     * @throws CommandException If event id is null after sync
     */
    public function addUserRegistered(int $userId): DbEvent
    {
        return $this->add(ChatEventType::USER_REGISTERED->value, userId: $userId);
    }

    /**
     * Appends the event emitted when a user renames themselves.
     *
     * @param int $userId Renamed user id
     * @param string $oldName Previous display name
     * @param string $newName New display name
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws CommandException If event id is null after sync
     */
    public function addUserRenamed(int $userId, string $oldName, string $newName): DbEvent
    {
        return $this->add(
            ChatEventType::USER_RENAMED->value,
            userId: $userId,
            data: [
                'oldName' => $oldName,
                'newName' => $newName,
            ],
        );
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
     * @throws CommandException If event id is null after sync
     */
    public function addUserRenamedByAdmin(
        int $userId,
        string $oldName,
        string $newName,
        ?int $adminUserId = null,
    ): DbEvent
    {
        return $this->add(
            ChatEventType::USER_RENAMED_BY_ADMIN->value,
            userId: $userId,
            data: [
                'oldName' => $oldName,
                'newName' => $newName,
                'adminUserId' => $adminUserId,
            ],
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
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws CommandException If event id is null after sync
     */
    public function addMessage(string $message, ?int $userId = null, ?int $botId = null): DbEvent
    {
        return $this->add(
            ChatEventType::MESSAGE_SENT->value,
            userId: $userId,
            botId: $botId,
            data: ['message' => $message],
        );
    }

    /**
     * Appends the event emitted when a user shares a file in chat.
     *
     * @param int $userId Uploading user id
     * @param string $originalFilename Original client filename
     * @param string $mimeType Published MIME type
     * @param int $size File size in bytes
     * @param string $downloadToken Public download token
     * @param string $storedName Internal stored filename
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws CommandException If event id is null after sync
     */
    public function addFile(
        int $userId,
        string $originalFilename,
        string $mimeType,
        int $size,
        string $downloadToken,
        string $storedName,
    ): DbEvent
    {
        return $this->add(
            ChatEventType::FILE_SHARED->value,
            userId: $userId,
            data: [
                'originalFilename' => $originalFilename,
                'mimeType' => $mimeType,
                'size' => $size,
                'downloadToken' => $downloadToken,
                'storedName' => $storedName,
            ],
        );
    }

    /**
     * Adds a new event to the collection and persists it to the database.
     *
     * @param string $type Event type
     * @param ?int $userId User ID for user-authored events
     * @param ?int $botId Bot ID for bot-authored events
     * @param ?array<string, mixed> $data Additional event payload
     * @return DbEvent Created event
     * @throws HilosException On database or truth-source failure
     * @throws CommandException If event id is null after sync
     */
    private function add(string $type, ?int $userId = null, ?int $botId = null, ?array $data = null): DbEvent
    {
        $this->ensureCanWrite();

        $objectEvent = ObjectEvent::create();
        $objectEvent->userId = $userId;
        $objectEvent->botId = $botId;
        $objectEvent->type = $type;
        $objectEvent->timestamp = TimeHelper::getSqlDateTime();
        $objectEvent->data = $data === null ? null : json_encode($data);
        $objectEvent->sync();

        if ($objectEvent->id === null) {
            throw new CommandException("Failed to save event to database: id is null after sync");
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

        $this->objectCollection->deleteAll();

        $this->clearCollectionCache();
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\EventMessage;
use Demo\Chat\Database\Object\Collection\EventMessages as ObjectEventMessages;
use Demo\Chat\Database\Object\Item\EventMessage as ObjectEventMessage;
use Demo\Chat\Database\View\Collection\EventMessages as DbCollectionEventMessages;
use Demo\Chat\Database\View\Item\EventMessage as DbEventMessage;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;

/**
 * EventMessagesActions - write operations for message event details.
 *
 * @extends DbActions<DbEventMessage, ObjectEventMessages>
 * @property-read DbCollectionEventMessages $collection
 * @property-read ObjectEventMessages $objectCollection
 */
final class EventMessagesActions extends DbActions
{
    /**
     * Get table name for EventMessages collection.
     *
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return EventMessage::_table;
    }

    /**
     * Creates scalar detail for one message event.
     *
     * @param int $eventId Parent event id
     * @param ?int $authorUserId Authoring user id
     * @param ?int $authorBotId Authoring bot id
     * @param string $message Published message text
     * @return DbEventMessage Created message detail
     * @throws HilosException On database or truth-source failure
     */
    public function create(int $eventId, ?int $authorUserId, ?int $authorBotId, string $message): DbEventMessage
    {
        $this->ensureCanCreateInSet((string)$eventId);

        $detail = ObjectEventMessage::create();
        $detail->eventId = $eventId;
        $detail->authorUserId = $authorUserId;
        $detail->authorBotId = $authorBotId;
        $detail->message = $message;
        $detail->sync();

        $this->addObjectToCollection($detail);

        return $this->createDbItemFromObject($detail);
    }

    /**
     * Re-points every message authored by a loser user to a survivor user (HIL-378).
     *
     * ChatAgent runs its merge participant under its own collection claim. Each message moves
     * through its object's {@see ObjectEventMessage::sync()}, so viewers receive the authorship
     * diff through DB sync. The participant's transaction records its moved count with its result;
     * a failure rolls back that part, and a repeated completed part returns the saved count.
     *
     * @param int $fromUserId Loser user id whose messages are absorbed
     * @param int $toUserId Survivor user id that receives the messages
     * @return int Number of messages re-pointed to the survivor
     * @throws HilosException On database or truth-source failure
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function rePointAuthor(int $fromUserId, int $toUserId): int
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);

        $moved = 0;
        foreach (EventMessage::get([EventMessage::author_user_id => $fromUserId]) as $entityMessage) {
            $eventId = $entityMessage->event_id;

            $message = $this->objectCollection[$eventId];
            if ($message === null) {
                $message = ObjectEventMessage::fromEntity($entityMessage);
                $this->objectCollection[$eventId] = $message;
            }

            $message->authorUserId = $toUserId;
            $message->sync();
            $moved++;
        }

        return $moved;
    }

    /**
     * Deletes every message detail row and clears the collection cache.
     *
     * @throws HilosException On database or truth-source failure
     */
    public function deleteAll(): void
    {
        TruthSourceRegistry::checkCanWrite(ChatDbContext::eventMessages, TruthSourceOperation::Remove);

        $this->deleteAllObjects();
    }

    /**
     * Deletes every message a person wrote - the account is being erased (HIL-302).
     *
     * Each message leaves through its object, so every reader hears the delete, as the merge's
     * re-point is heard. The attachments of these messages are the caller's to delete first
     * ({@see EventAttachmentsActions::deleteForMessages()}), and the events they belong to
     * after. Runs inside the erasure transaction for ChatAgent's part (HIL-1949).
     *
     * @param int $userId Author user id
     * @return int Number of messages deleted
     * @throws HilosException On database or truth-source failure
     */
    public function deleteByAuthor(int $userId): int
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        $deleted = 0;
        foreach (EventMessage::get([EventMessage::author_user_id => $userId]) as $entityMessage) {
            $eventId = $entityMessage->event_id;
            $message = $this->objectCollection[$eventId] ?? ObjectEventMessage::fromEntity($entityMessage);
            $message->delete();
            unset($this->objectCollection[$eventId]);
            $deleted++;
        }

        return $deleted;
    }
}

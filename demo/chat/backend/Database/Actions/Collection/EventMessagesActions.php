<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\EventMessage;
use Demo\Chat\Database\Object\Collection\EventMessages as ObjectEventMessages;
use Demo\Chat\Database\Object\Item\EventMessage as ObjectEventMessage;
use Demo\Chat\Database\View\Collection\EventMessages as DbCollectionEventMessages;
use Demo\Chat\Database\View\Item\EventMessage as DbEventMessage;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Object\Collection\Identities;
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
        TruthSourceRegistry::checkCanCreate(ChatDbContext::eventMessages);
        $this->ensureCanWrite();

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
     * The demo content-transfer half of account merge, symmetric with the
     * framework identity re-point ({@see Identities::rePointToUser()}):
     * the survivor absorbs the loser's chat messages. Each message is moved
     * through its object's {@see ObjectEventMessage::sync()} rather than a single
     * bulk UPDATE, exactly like the identity re-point, so every moved row emits a
     * DB_SYNC_UPDATED broadcast — the workers apply the authorship diff and
     * re-render the messages for viewers. A raw bulk UPDATE would emit no DB_SYNC,
     * leaving the transferred authorship invisible until a full reload (the merge
     * is a rare admin op, so the per-message sync cost is irrelevant). Returns the
     * number of messages re-pointed (the merge summary's "messages moved"). Runs
     * inside the merge transaction, so a failure rolls the whole merge back.
     *
     * @param int $fromUserId Loser user id whose messages are absorbed
     * @param int $toUserId Survivor user id that receives the messages
     * @return int Number of messages re-pointed to the survivor
     * @throws HilosException On database or truth-source failure
     */
    public function rePointAuthor(int $fromUserId, int $toUserId): int
    {
        TruthSourceRegistry::checkCanWrite(ChatDbContext::eventMessages);
        $this->ensureCanWrite();

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
        TruthSourceRegistry::checkCanWrite(ChatDbContext::eventMessages);
        $this->ensureCanWrite();

        $this->objectCollection->deleteAll();

        $this->clearCollectionCache();
    }
}

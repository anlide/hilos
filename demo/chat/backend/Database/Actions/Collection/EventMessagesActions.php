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
use Hilos\Database\Database;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
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
     * framework identity re-point ({@see \Hilos\Database\Object\Collection\Identities::rePointToUser()}):
     * the survivor absorbs the loser's chat messages. Only the authoring column
     * changes and there is no per-user projection to re-emit here (transferred
     * messages become visible through the post-merge survivor refresh), so the
     * move is a single targeted UPDATE rather than a per-object sync — the same
     * targeted-write shape the framework identity primitives use. Returns the
     * number of messages re-pointed (the merge summary's "messages moved") and
     * clears the now-stale object cache. Runs inside the merge transaction, so a
     * failure rolls the whole merge back.
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

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($toUserId));
        $params->add(SqlParam::int($fromUserId));
        Database::sql(
            'UPDATE `' . EventMessage::_table . '` SET `' . EventMessage::author_user_id . '` = ? WHERE `' . EventMessage::author_user_id . '` = ?',
            $params,
        );

        $moved = Database::affectedRows();

        $this->clearCollectionCache();

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

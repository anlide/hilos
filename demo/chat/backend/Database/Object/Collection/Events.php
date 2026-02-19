<?php

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\Entity\Collection\Events as EntityEvents;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Hilos\TruthSource\Exception\WriteNotAllowedException;
use RuntimeException;

/**
 * Chat events object collection.
 *
 * @extends Objects<ObjectEvent>
 */
final class Events extends Objects
{
    public const string OBJECT_CLASS = ObjectEvent::class;
    public const string ENTITY_COLLECTION_CLASS = EntityEvents::class;
    public const string COLLECTION_KEY = DbChatContext::events;

    /**
     * Deletes all events from database and clears the collection.
     */
    public function deleteAll(): void
    {
        Database::sql("DELETE FROM `event` WHERE true;");
        $this->objects = [];
    }

    /**
     * Adds new event to collection and persists to database.
     *
     * @param string $type Event type
     * @param ?int $userId User ID (optional)
     * @param ?array $data Additional event data (optional)
     * @return ObjectEvent Created event object
     */
    public function add(string $type, ?int $userId = null, ?array $data = null): ObjectEvent
    {
        if ($this->_lazyStrategy === Objects::LAZY_STRATEGY_NONE) {
            $hasTruthSource = TruthSourceRegistry::hasTruthSource(DbChatContext::events);

            if (!$hasTruthSource) {
                throw new WriteNotAllowedException("Write operation not allowed: no truth source registered for collection '" . DbChatContext::events . "'. Register via TruthSourceRegistry::register() first.");
            }

            if (!$this->_allLoaded) {
                $this->loadAllFromDB();
            }
        }

        $objectEvent = ObjectEvent::create();
        $objectEvent->userId = $userId;
        $objectEvent->type = $type;
        $objectEvent->timestamp = date('Y-m-d H:i:s');
        $objectEvent->data = $data === null ? null : json_encode($data);
        $objectEvent->sync();

        if ($objectEvent->id === null) {
            throw new RuntimeException("Failed to save event to database: id is null after sync");
        }

        $this->objects[$objectEvent->id] = $objectEvent;
        return $objectEvent;
    }
}

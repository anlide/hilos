<?php

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\Entity\Collection\Events as EntityEvents;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Hilos\Database\Object\Objects;

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
}

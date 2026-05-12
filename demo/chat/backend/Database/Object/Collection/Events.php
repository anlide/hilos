<?php

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\Entity\Collection\Events as EntityEvents;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Hilos\Database\Object\Objects;

/**
 * Events - Object collection for chat events.
 *
 * @extends Objects<ObjectEvent>
 * @method ObjectEvent|null current()
 * @method ObjectEvent|null first()
 * @method ObjectEvent|null last()
 * @method ObjectEvent|null get(int|string $key)
 * @method ObjectEvent|null offsetGet(mixed $offset)
 */
final class Events extends Objects
{
    public const string OBJECT_CLASS = ObjectEvent::class;
    public const string ENTITY_COLLECTION_CLASS = EntityEvents::class;
    public const string COLLECTION_KEY = ChatDbContext::events;
}

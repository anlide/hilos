<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Collection\EventMessages as EntityEventMessages;
use Demo\Chat\Database\Object\Item\EventMessage as ObjectEventMessage;
use Hilos\Database\Object\Objects;

/**
 * EventMessages - Object collection for message event details.
 *
 * @extends Objects<ObjectEventMessage>
 * @method ObjectEventMessage|null current()
 * @method ObjectEventMessage|null first()
 * @method ObjectEventMessage|null last()
 * @method ObjectEventMessage|null get(int|string $key)
 * @method ObjectEventMessage|null offsetGet(mixed $offset)
 */
final class EventMessages extends Objects
{
    public const string OBJECT_CLASS = ObjectEventMessage::class;
    public const string ENTITY_COLLECTION_CLASS = EntityEventMessages::class;
    public const string COLLECTION_KEY = ChatDbContext::eventMessages;
}

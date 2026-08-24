<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Collection;

use ArrayAccess;
use Demo\Chat\Database\Entity\Item\EventMessage as EntityEventMessage;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * EventMessages - Entity collection for event_message rows.
 *
 * @extends EntityCollection<EntityEventMessage>
 * @implements IteratorAggregate<int|string, EntityEventMessage>
 * @implements ArrayAccess<int|string, EntityEventMessage>
 */
final class EventMessages extends EntityCollection
{
    public const string ENTITY_CLASS = EntityEventMessage::class;
}

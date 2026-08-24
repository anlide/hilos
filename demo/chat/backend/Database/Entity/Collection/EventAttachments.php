<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Collection;

use ArrayAccess;
use Demo\Chat\Database\Entity\Item\EventAttachment as EntityEventAttachment;
use Hilos\Database\Entity\Collection\EntityCollection;
use IteratorAggregate;

/**
 * EventAttachments - Entity collection for event attachments.
 *
 * @extends EntityCollection<EntityEventAttachment>
 * @implements IteratorAggregate<int|string, EntityEventAttachment>
 * @implements ArrayAccess<int|string, EntityEventAttachment>
 */
final class EventAttachments extends EntityCollection
{
    public const string ENTITY_CLASS = EntityEventAttachment::class;
}

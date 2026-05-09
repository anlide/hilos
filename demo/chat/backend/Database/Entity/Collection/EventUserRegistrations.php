<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Collection;

use ArrayAccess;
use Demo\Chat\Database\Entity\Item\EventUserRegistration as EntityEventUserRegistration;
use Hilos\Database\Entity\Collection\EntityCollection;
use Iterator;

/**
 * EventUserRegistrations - Entity collection for event_user_registration rows.
 *
 * @extends EntityCollection<EntityEventUserRegistration>
 * @implements Iterator<int|string, EntityEventUserRegistration>
 * @implements ArrayAccess<int|string, EntityEventUserRegistration>
 */
final class EventUserRegistrations extends EntityCollection
{
    public const string ENTITY_CLASS = EntityEventUserRegistration::class;
}

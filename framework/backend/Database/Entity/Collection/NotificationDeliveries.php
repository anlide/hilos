<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;

/**
 * NotificationDeliveries entity collection.
 *
 * @extends EntityCollection<EntityNotificationDelivery>
 */
final class NotificationDeliveries extends EntityCollection
{
    public const string ENTITY_CLASS = EntityNotificationDelivery::class;
}

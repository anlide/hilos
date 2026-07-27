<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\Notification as EntityNotification;

/**
 * Notifications entity collection.
 *
 * @extends EntityCollection<EntityNotification>
 */
final class Notifications extends EntityCollection
{
    public const string ENTITY_CLASS = EntityNotification::class;
}

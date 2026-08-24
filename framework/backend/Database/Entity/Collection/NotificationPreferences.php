<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\NotificationPreference as EntityNotificationPreference;
use IteratorAggregate;

/**
 * NotificationPreferences - Entity collection for the framework hilos_notification_preference table.
 *
 * @extends EntityCollection<EntityNotificationPreference>
 * @implements IteratorAggregate<int|string, EntityNotificationPreference>
 * @implements ArrayAccess<int|string, EntityNotificationPreference>
 */
final class NotificationPreferences extends EntityCollection
{
    public const string ENTITY_CLASS = EntityNotificationPreference::class;
}

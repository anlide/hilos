<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\NotificationPreferences as ObjectNotificationPreferences;
use Hilos\Database\View\Item\NotificationPreference;

/**
 * NotificationPreferences Db collection.
 *
 * Read-facing representation of the framework-owned hilos_notification_preference
 * table (HIL-485). The profile section reads a recipient's muted channels through
 * the object collection's {@see ObjectNotificationPreferences::mutedChannels()};
 * the collection action applies an opt in/out.
 *
 * @extends DbCollection<NotificationPreference, ObjectNotificationPreferences>
 */
final class NotificationPreferences extends DbCollection
{
    public const string DB_ITEM_CLASS = NotificationPreference::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectNotificationPreferences::class;
}

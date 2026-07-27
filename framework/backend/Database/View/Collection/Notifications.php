<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\View\Item\Notification;

/**
 * Notifications Db collection.
 *
 * Read-facing representation of the framework-owned hilos_notification table. The
 * notification-center page (HIL-195) reads a recipient's list through the object
 * collection's {@see ObjectNotifications::listForUser()}; the collection action
 * marks a recipient's notifications read.
 *
 * @extends DbCollection<Notification, ObjectNotifications>
 */
final class Notifications extends DbCollection
{
    public const string DB_ITEM_CLASS = Notification::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectNotifications::class;
}

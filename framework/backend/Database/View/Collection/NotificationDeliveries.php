<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\View\Item\NotificationDelivery;

/**
 * NotificationDeliveries Db collection.
 *
 * Read-facing representation of the framework-owned hilos_notification_delivery
 * table (HIL-196). The dispatcher creates pending rows and the channel agents drive
 * them through the object collection; the delivery-logs UI (HIL-201) reads them.
 *
 * @extends DbCollection<NotificationDelivery, ObjectNotificationDeliveries>
 */
final class NotificationDeliveries extends DbCollection
{
    public const string DB_ITEM_CLASS = NotificationDelivery::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectNotificationDeliveries::class;
}

<?php

declare(strict_types=1);

namespace Hilos\Notification;

/**
 * NotificationSignalName - server → client signal names of the notification center.
 *
 * The two live in-app signals of the durable notification model (HIL-102),
 * fanned to a recipient's connections through the per-user notification group
 * ({@see NotificationGroup}). The notification-center page (HIL-195) subscribes a
 * recipient's connections to that group and reacts to these signals.
 */
final class NotificationSignalName
{
    /** A new notification was created for the recipient (carries the row). */
    public const string CREATED = 'notification_created';

    /** One notification, or all of them, was marked read (badge/multi-device sync). */
    public const string READ = 'notification_read';
}

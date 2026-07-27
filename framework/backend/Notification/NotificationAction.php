<?php

declare(strict_types=1);

namespace Hilos\Notification;

/**
 * NotificationAction - client → server action names of the notification center.
 *
 * The two write actions of the durable notification model (HIL-102). They are
 * declared here and mounted on the notification-center page in HIL-195, which
 * dispatches each to the matching Db action, tracks it as a loading operation for
 * the clicker, and fans {@see NotificationSignalName::READ} to the recipient's
 * other connections.
 */
final class NotificationAction
{
    /** Mark one notification read (payload carries its id). */
    public const string MARK_READ = 'notification_mark_read';

    /** Mark every unread notification of the recipient read (no payload fields). */
    public const string MARK_ALL_READ = 'notification_mark_all_read';
}

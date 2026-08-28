<?php

declare(strict_types=1);

namespace Hilos\Notification;

use Hilos\Groups\AbstractHilosNotificationsGroup;

/**
 * NotificationAction - client → server action names of the notification center.
 *
 * The client → server actions mounted on the notification-center page in HIL-195.
 * Both dispatch to the matching Db action, are tracked as a loading operation for the
 * clicker, and fan {@see NotificationSignalName::READ} to the recipient's other
 * connections. There is no read action beside them: the recipient's snapshot is what
 * the per-user group answers a join with ({@see AbstractHilosNotificationsGroup}), so
 * nothing has to be asked for after connecting (HIL-721).
 */
final class NotificationAction
{
    /** Mark one notification read (payload carries its id). */
    public const string MARK_READ = 'notification_mark_read';

    /** Mark every unread notification of the recipient read (no payload fields). */
    public const string MARK_ALL_READ = 'notification_mark_all_read';
}

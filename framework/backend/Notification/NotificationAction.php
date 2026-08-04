<?php

declare(strict_types=1);

namespace Hilos\Notification;

use Hilos\Pages\AbstractHilosNotificationsPage;

/**
 * NotificationAction - client → server action names of the notification center.
 *
 * The client → server actions mounted on the notification-center page in HIL-195.
 * The two write actions ({@see MARK_READ} / {@see MARK_ALL_READ}) each dispatch to
 * the matching Db action, are tracked as a loading operation for the clicker, and
 * fan {@see NotificationSignalName::READ} to the recipient's other connections. The
 * read action ({@see SYNC}) carries no payload and returns the recipient's snapshot;
 * the client sends it once at connect (the notification center joins the per-user
 * group for live updates but has no page subscription — see
 * {@see AbstractHilosNotificationsPage}).
 */
final class NotificationAction
{
    /** Mark one notification read (payload carries its id). */
    public const string MARK_READ = 'notification_mark_read';

    /** Mark every unread notification of the recipient read (no payload fields). */
    public const string MARK_ALL_READ = 'notification_mark_all_read';

    /** Request the recipient's snapshot — recent rows + unread count (no payload fields). */
    public const string SYNC = 'notification_sync';
}

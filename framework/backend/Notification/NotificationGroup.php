<?php

declare(strict_types=1);

namespace Hilos\Notification;

/**
 * NotificationGroup - the per-recipient WebSocket group the notification center fans over.
 *
 * A recipient's live connections are addressed by group name rather than by a
 * framework-level user → connection map (which does not exist: presence is a
 * project-provided runtime collection). The emitting worker fans
 * {@see NotificationSignalName::CREATED} / {@see NotificationSignalName::READ} to
 * this group; the notification-center page (HIL-195) subscribes the recipient's
 * connections to it. When no connection is subscribed (recipient offline at emit),
 * the fan is a no-op and the durable row still persists — the unread badge
 * recovers from a COUNT on the next subscribe.
 */
final class NotificationGroup
{
    /** Group-name prefix; the recipient user id is appended. */
    public const string PREFIX = 'hilos_notifications:';

    /**
     * Builds the notification group name for a recipient.
     *
     * @param int $userId Recipient user id
     * @return string Group name the recipient's connections subscribe to
     */
    public static function forUser(int $userId): string
    {
        return self::PREFIX . $userId;
    }
}

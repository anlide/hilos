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
    /** Name the group class declares, and the head of every full name it answers to. */
    public const string NAME = 'hilos_notifications';

    /** Group-name prefix; the recipient user id is appended. */
    public const string PREFIX = self::NAME . ':';

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

    /**
     * Reads the recipient back out of a full group name.
     *
     * The inverse of {@see self::forUser()}, and the one way the group class learns whose
     * notifications it is answering for: the name is what the server's own address resolution
     * settled on, so reading the recipient off it cannot disagree with the join.
     *
     * @param string $group Full group name
     * @return ?int Recipient user id, or null when the name carries none
     */
    public static function userOf(string $group): ?int
    {
        if (!str_starts_with($group, self::PREFIX)) {
            return null;
        }

        $userId = substr($group, strlen(self::PREFIX));

        return ctype_digit($userId) ? (int)$userId : null;
    }
}

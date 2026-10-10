<?php

declare(strict_types=1);

namespace Hilos\Notification;

/**
 * NotificationPreferenceAction - client → server action names of the profile notifications section.
 *
 * The per-user channel-preference action (HIL-485). The notifications library judges the
 * toggle and the person's agent writes the sparse muted row. The acting user is resolved
 * server-side from the connection, never the payload, so a client can only ever change its
 * own preferences.
 */
final class NotificationPreferenceAction
{
    /** Set one channel's opt in/out (payload carries the channel name and the desired state). */
    public const string CHANNEL_SET = 'profile_notification_channel_set';
}

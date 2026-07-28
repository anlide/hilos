<?php

declare(strict_types=1);

namespace Hilos\Notification;

/**
 * NotificationPreferenceAction - client → server action names of the profile notifications section.
 *
 * The per-user channel-preference action (HIL-485), mounted on the profile page
 * (an AUTHENTICATED surface). Toggling a channel dispatches to the
 * {@see \Hilos\Database\Actions\Collection\NotificationPreferencesActions}
 * setChannel write, is tracked as a loading operation for the clicker, and fans
 * {@see NotificationSignalName::PREFERENCES_CHANGED} to the recipient's other
 * connections. The acting user is resolved server-side from the connection, never
 * the payload, so a client can only ever change its own preferences.
 */
final class NotificationPreferenceAction
{
    /** Set one channel's opt in/out (payload carries the channel name and the desired state). */
    public const string CHANNEL_SET = 'profile_notification_channel_set';
}

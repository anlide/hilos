<?php

declare(strict_types=1);

namespace Demo\Tasks\Constants;

/**
 * TasksNotificationType - the notification types this demo raises from its own domain (HIL-557).
 *
 * The demo has no domain content of its own yet, so the one event worth a notification
 * is the one that really happens here: an administrator renames an account. The
 * arrival of a visitor used to be the other one, and went with the visitor's user row
 * (HIL-610) - a notification is addressed to a user id, and a guest has none. Names
 * only - no type registry is declared, because a descriptor carries nothing but the
 * mandatory flag today and an unregistered type is already non-mandatory. It stays
 * non-mandatory on purpose: it is not a security notification, so the channel
 * preferences (HIL-485) are entitled to mute it.
 */
final class TasksNotificationType
{
    /** An administrator renamed the recipient's account. */
    public const string USER_RENAMED = 'tasks.user.renamed';
}

<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Constants;

/**
 * PollNotificationType - the notification types this demo raises from its own domain (HIL-557).
 *
 * The demo has no domain content of its own yet, so the events worth a notification
 * are the ones that really happen here: an account appears, and an administrator
 * renames one. Names only - no type registry is declared, because a descriptor
 * carries nothing but the mandatory flag today and an unregistered type is already
 * non-mandatory. Both stay non-mandatory on purpose: neither is a security
 * notification, so the channel preferences (HIL-485) are entitled to mute them.
 */
final class PollNotificationType
{
    /** A new visitor's session registered an account. */
    public const string USER_REGISTERED = 'poll.user.registered';

    /** An administrator renamed the recipient's account. */
    public const string USER_RENAMED = 'poll.user.renamed';
}

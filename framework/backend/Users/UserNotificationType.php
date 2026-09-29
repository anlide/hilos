<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Notification\NotificationTypeRegistry;

/**
 * UserNotificationType - the machine types a change to a person is announced under (HIL-1195).
 *
 * Declared by the framework because the code that emits them is the framework's: the users
 * library renames a person ({@see AbstractUsersLibraryAgent::renameUser()}). Names only - the
 * type is not registered in {@see NotificationTypeRegistry}, because a descriptor carries
 * nothing but the mandatory flag today and an unregistered type is already non-mandatory. It
 * stays non-mandatory on purpose: a rename is not a security notification, so the channel
 * preferences (HIL-485) are entitled to mute it.
 */
final class UserNotificationType
{
    /** Somebody other than the recipient renamed the recipient's account. */
    public const string RENAMED = 'user.renamed';
}

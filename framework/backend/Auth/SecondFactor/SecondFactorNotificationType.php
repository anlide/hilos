<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Notification\NotificationTypeRegistry;

/**
 * SecondFactorNotificationType - the machine types a delayed removal of a second factor is announced under (HIL-494).
 *
 * Declared by the framework because the code that emits them is the framework's: the users
 * library opens and cancels a removal, and its sweep reminds and carries it out. All four are
 * registered as mandatory in {@see NotificationTypeRegistry}: whoever took over an account
 * would switch the person's notifications off first, and the announcement is the defence.
 */
final class SecondFactorNotificationType
{
    /** A removal was asked for; the announcement carries the cancel link. */
    public const string RESET_REQUESTED = 'second_factor.reset_requested';

    /** A removal still stands; sent once a day until it is carried out, with the cancel link. */
    public const string RESET_REMINDER = 'second_factor.reset_reminder';

    /** A removal was canceled - by the link, by the profile, or by the second factor shown after all. */
    public const string RESET_CANCELED = 'second_factor.reset_canceled';

    /** A removal was carried out: the account has no second factor any more. */
    public const string RESET_COMPLETED = 'second_factor.reset_completed';
}

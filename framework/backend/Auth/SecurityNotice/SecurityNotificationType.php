<?php

declare(strict_types=1);

namespace Hilos\Auth\SecurityNotice;

use Hilos\Notification\NotificationTypeRegistry;

/**
 * SecurityNotificationType - the machine types the security events of an account are announced under (HIL-294).
 *
 * Declared by the framework because the code that emits them is the framework's: the person's
 * agent writes the password and the block flag, and the sessions holder signs a browser in. All
 * five are registered as mandatory in {@see NotificationTypeRegistry}: whoever took over an
 * account would switch the person's notifications off first, and the announcement is the defence.
 */
final class SecurityNotificationType
{
    /** The password was changed in the profile. */
    public const string PASSWORD_CHANGED = 'security.password_changed';

    /** The password was reset through password recovery, and every other session was signed out. */
    public const string PASSWORD_RESET = 'security.password_reset';

    /** A browser was signed in to the account. */
    public const string SIGNED_IN = 'security.signed_in';

    /** An administrator blocked the account. */
    public const string ACCOUNT_BLOCKED = 'security.account_blocked';

    /** An administrator lifted the block on the account. */
    public const string ACCOUNT_UNBLOCKED = 'security.account_unblocked';
}

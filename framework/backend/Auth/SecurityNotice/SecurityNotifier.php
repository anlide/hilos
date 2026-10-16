<?php

declare(strict_types=1);

namespace Hilos\Auth\SecurityNotice;

use Hilos\Auth\SecondFactor\SecondFactorResetNotifier;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Hilos;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;

/**
 * Announces the security events of an account on every channel (HIL-294).
 *
 * Five announcements ({@see SecurityNotificationType}), all mandatory: the password changed in
 * the profile, the password reset through recovery, a sign-in, the account blocked and the block
 * lifted. Each is sent by whoever wrote the fact, once the write went through - the person's
 * agent for the password and the block flag, the sessions holder for a sign-in. No channel is
 * chosen here: a mandatory type goes to every enabled channel the person has an address on,
 * whatever their own preferences say, and the bell row is written always. None carries a link -
 * "it was not me" is the text about password recovery - and none carries the time: a letter has
 * its own date, a bell row its own moment.
 *
 * An installation with no notifier wired announces nothing; the event happens all the same. The
 * shape is {@see SecondFactorResetNotifier}'s.
 */
final class SecurityNotifier
{
    /**
     * Announces a password changed in the profile.
     *
     * @param int $userId Person whose password was changed
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    public static function passwordChanged(int $userId): void
    {
        self::emit(
            $userId,
            SecurityNotificationType::PASSWORD_CHANGED,
            'Your password was changed',
            NotificationSeverity::WARNING,
            'The password of your account was changed. If this was not you, reset your password from the sign-in screen'
                . ' (Forgot password?) right away - that signs out every other session.',
        );
    }

    /**
     * Announces a password reset through password recovery.
     *
     * @param int $userId Person whose password was reset
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    public static function passwordReset(int $userId): void
    {
        self::emit(
            $userId,
            SecurityNotificationType::PASSWORD_RESET,
            'Your password was reset',
            NotificationSeverity::WARNING,
            'The password of your account was reset through password recovery, and every other session was signed out.'
                . ' If this was not you, reset it again from the sign-in screen (Forgot password?)'
                . ' and change the password of your email account too.',
        );
    }

    /**
     * Announces a browser signed in to the account.
     *
     * The device and the address are named when the session knows them; a part whose value is
     * missing or empty is left out whole.
     *
     * @param int $userId Person who was signed in
     * @param ?string $deviceName Device name of the session, or null when it has none
     * @param ?string $address Address of the session, or null when it has none
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    public static function signedIn(int $userId, ?string $deviceName, ?string $address): void
    {
        $where = '';
        if ($deviceName !== null && $deviceName !== '') {
            $where .= " on {$deviceName}";
        }
        if ($address !== null && $address !== '') {
            $where .= " from {$address}";
        }

        self::emit(
            $userId,
            SecurityNotificationType::SIGNED_IN,
            'New sign-in to your account',
            NotificationSeverity::INFO,
            "Your account was signed in{$where}. If this was not you, reset your password from the sign-in screen"
                . ' (Forgot password?) - that signs out every other session. You can also end that session in your profile'
                . ' under Sessions.',
        );
    }

    /**
     * Announces the account blocked by an administrator.
     *
     * @param int $userId Person whose account was blocked
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    public static function accountBlocked(int $userId): void
    {
        self::emit(
            $userId,
            SecurityNotificationType::ACCOUNT_BLOCKED,
            'Your account was blocked',
            NotificationSeverity::WARNING,
            'An administrator blocked your account. You cannot sign in until the block is lifted.'
                . " If you think this is a mistake, contact the service's support.",
        );
    }

    /**
     * Announces the block on the account lifted by an administrator.
     *
     * @param int $userId Person whose account was unblocked
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    public static function accountUnblocked(int $userId): void
    {
        self::emit(
            $userId,
            SecurityNotificationType::ACCOUNT_UNBLOCKED,
            'Your account was unblocked',
            NotificationSeverity::INFO,
            'An administrator lifted the block on your account. You can sign in again.',
        );
    }

    /**
     * Hands one announcement to the notifier.
     *
     * @param int $userId Recipient
     * @param string $type Notification type
     * @param string $title Title
     * @param string $severity Importance ({@see NotificationSeverity} value)
     * @param string $body Body
     * @throws InvalidArgumentException When the emit signal cannot be named or queued
     */
    private static function emit(int $userId, string $type, string $title, string $severity, string $body): void
    {
        Hilos::$notify?->emit(new NotificationDraft(
            userId: $userId,
            type: $type,
            title: $title,
            severity: $severity,
            body: $body,
            data: null,
        ));
    }
}

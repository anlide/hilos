<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

/**
 * Live reasons an authenticated session became anonymous, carried only by its ending frame.
 */
final class SessionAccessLossReason
{
    public const string SESSION_ENDED = 'session_ended';
    public const string ACCOUNT_BLOCKED = 'account_blocked';
    public const string ENDED_BY_ADMINISTRATOR = 'ended_by_administrator';
    public const string ENDED_FROM_ANOTHER_BROWSER = 'ended_from_another_browser';
    public const string PASSWORD_CHANGED = 'password_changed';
    public const string PASSWORD_RECOVERED = 'password_recovered';

    public const array ALL = [
        self::SESSION_ENDED,
        self::ACCOUNT_BLOCKED,
        self::ENDED_BY_ADMINISTRATOR,
        self::ENDED_FROM_ANOTHER_BROWSER,
        self::PASSWORD_CHANGED,
        self::PASSWORD_RECOVERED,
    ];
}

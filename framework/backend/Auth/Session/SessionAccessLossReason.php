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
}

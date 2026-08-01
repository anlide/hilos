<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle;

/**
 * ThrottleScope - fixed value set for the throttle key scope (HIL-420).
 *
 * Mirrors the SQL ENUM on `hilos_auth_block`.`scope` and the scope segment of
 * the RT AuthAttempts key. A throttle key is (scope, identity, action): `ip`
 * keys on the client IP (shared across a NAT), `session` keys on the sha256 of
 * the session token (per browser). A successful auth resets the `session`
 * scope but never the `ip` scope, so one abusive session cannot lift the
 * IP-wide pressure it created.
 */
final class ThrottleScope
{
    public const string IP = 'ip';
    public const string SESSION = 'session';
}

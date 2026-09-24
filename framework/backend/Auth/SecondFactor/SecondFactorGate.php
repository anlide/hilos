<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * The question every proven sign-in is asked before it is let in (HIL-494).
 *
 * Asked by the session holder ({@see AbstractSessionsLibraryAgent}) on every path that ends a
 * proof - a password, a phone code, a link, a passkey, a provider, a new password saved by
 * recovery - and on none that is not a proof: a registration lands an account that has no
 * second factor yet, and an operator's `admin:create` or takeover is no sign-in by the person.
 *
 * Three answers. A person with a confirmed authenticator is let through only on a browser they
 * asked to trust and whose trust has not run out; elsewhere the sign-in waits on the code step.
 * A person without one is let through - unless the administrator requires a second factor of
 * them, in which case the sign-in waits on enrolment.
 */
final class SecondFactorGate
{
    /** Let the sign-in through. */
    public const string PASS = 'pass';

    /** Hold it on the code step. */
    public const string VERIFY = 'verify';

    /** Hold it on enrolment on the way in. */
    public const string SETUP = 'setup';

    /**
     * Decides what a proven sign-in of one person on one browser meets.
     *
     * @param int $sessionId Session row of the browser the proof arrived on
     * @param int $userId Person the proof resolved to
     * @param SecondFactorPolicy $policy Administrator's settings in force
     * @return string One of PASS, VERIFY, SETUP
     * @throws DatabaseException When an authenticator or trust lookup fails
     * @throws InvalidArgumentException When a lookup query is malformed
     * @throws LogicException When a collection class constant is not configured
     * @throws HilosException When the project cannot say who its administrators are
     */
    public static function verdict(int $sessionId, int $userId, SecondFactorPolicy $policy): string
    {
        if (Hilos::$db->secondFactors->confirmedOf($userId) !== []) {
            return Hilos::$db->secondFactorTrusts->isTrusted($sessionId, $userId) ? self::PASS : self::VERIFY;
        }

        return $policy->requiresFor($userId) ? self::SETUP : self::PASS;
    }
}

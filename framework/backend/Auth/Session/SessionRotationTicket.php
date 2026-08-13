<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * SessionRotationTicket - the single owner of the rotation ticket's form (HIL-582).
 *
 * The one-time bearer of a token rotation. When a login rotates the session token,
 * the new token cannot be handed to the browser directly - the session cookie is
 * HttpOnly, so JavaScript cannot write it, and Set-Cookie is only possible on the
 * 101 the master builds. So the seam mints a ticket instead, sends it to the
 * initiating connection alone, and the master trades it for the new token on the
 * next handshake. Whoever presents the ticket gets the rotated session; everyone
 * presenting the old token gets a fresh anonymous one.
 *
 * A separate class from {@see SessionToken} rather than a reuse of it, although the
 * two forms coincide today: they are different secrets with different lifetimes and
 * different meanings, and a signature that takes one must not silently accept the
 * other. The ticket lives for seconds and is burned on first use; the token lives
 * for the session.
 *
 * Being a secret, it is drawn from the secure axis of RandomHelper (HIL-568): a
 * refusal of the platform's random source leaves as an exception rather than as a
 * guessable ticket nobody can tell from a minted one.
 */
final class SessionRotationTicket
{
    /** @var int Byte length drawn from the random source; a ticket is these bytes in hex */
    private const int RANDOM_BYTES = 16;

    /** @var int Ticket length in hex characters */
    private const int HEX_LENGTH = self::RANDOM_BYTES * 2;

    /** @var string Ticket form: lowercase hex, exactly HEX_LENGTH characters */
    private const string FORMAT_PATTERN = '/\A[0-9a-f]{' . self::HEX_LENGTH . '}\z/';

    /** @var int Seconds a minted ticket stays honoured */
    private const int LIFETIME_SECONDS = 30;

    /** @var string Appended to the session cookie name to name the cookie a ticket travels in */
    private const string COOKIE_NAME_SUFFIX = '_rotate';

    /**
     * Names the short-lived cookie a browser carries its ticket back in.
     *
     * Derived from the deployment's session cookie name rather than fixed, so an
     * installation that renamed its session cookie - or runs two Hilos apps on one host -
     * gets a ticket cookie that travels with it instead of one they collide on. The
     * frontend builds the same name from the session cookie name the welcome frame tells
     * it, which is the only reason that field is on the frame at all.
     *
     * @param string $sessionCookieName Session cookie name this deployment uses
     * @return string Name of the auxiliary cookie carrying a ticket
     */
    public static function cookieName(string $sessionCookieName): string
    {
        return $sessionCookieName . self::COOKIE_NAME_SUFFIX;
    }

    /**
     * Computes the instant a ticket minted now stops being honoured.
     *
     * The lifetime is not an attack window: the ticket reached one connection over its own
     * socket, so nobody else has it to spend. It is slack for the reconnect that spends it -
     * a browser on a slow network, a tab the scheduler kept waiting - and it is short because
     * a ticket outliving the reconnect it was minted for protects nothing and keeps the old
     * session's other connections alive past the moment they should have been dropped.
     *
     * @return float Unix milliseconds after which the ticket is refused
     */
    public static function expiryFromNow(): float
    {
        return (microtime(true) + self::LIFETIME_SECONDS) * TimeConstants::MS_PER_SECOND;
    }

    /**
     * @return string Freshly minted rotation ticket
     * @throws RandomException When the platform's secure random source refuses
     */
    public static function mint(): string
    {
        return RandomHelper::secureHex(self::RANDOM_BYTES);
    }

    /**
     * Predicate for the master, which drops a ticket it cannot use instead of failing:
     * a handshake carrying a malformed ticket is served by the ordinary cookie rule.
     *
     * @param string $ticket Ticket value to check
     * @return bool True when the value has the minted form
     */
    public static function isValid(string $ticket): bool
    {
        return preg_match(self::FORMAT_PATTERN, $ticket) === 1;
    }

    /**
     * @param string $ticket Ticket value to check
     * @throws InvalidFormatException When the value is not a 32-character lowercase hex ticket
     */
    public static function ensureValid(string $ticket): void
    {
        if (!self::isValid($ticket)) {
            throw new InvalidFormatException(
                'Invalid session rotation ticket format. Expected ' . self::HEX_LENGTH . ' lowercase hex characters.'
            );
        }
    }
}

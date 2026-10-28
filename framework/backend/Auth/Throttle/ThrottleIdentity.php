<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle;

use Hilos\Socket\Client\WebSocketClient;

/**
 * ThrottleIdentity - how sessions and normalized addresses become throttle keys.
 *
 * One recipe in one place, because two of them would be a bug nobody sees: the master
 * composes the identity when it stamps an action ({@see WebSocketClient}) and the session
 * host composes it again when that session finally authenticates, and a counter is only
 * cleared if both arrive at the same string.
 *
 * A digest rather than the token itself: the anti-abuse key may travel with action metadata,
 * but it cannot be presented as a session. Analytics drops action bodies before journaling;
 * that does not make a raw session token safe to use as an identity elsewhere.
 */
final class ThrottleIdentity
{
    /** Digest the session identity is composed with. */
    private const string ALGORITHM = 'sha256';

    /** Separates an address digest from the digest of the same string used as a session token. */
    private const string EMAIL_DOMAIN_TAG = 'email:';

    /**
     * @param string $normalizedEmail Validated address normalized by the action DTO
     * @return string Domain-separated digest shared by all browsers using this address
     */
    public static function forEmail(string $normalizedEmail): string
    {
        return hash(self::ALGORITHM, self::EMAIL_DOMAIN_TAG . $normalizedEmail);
    }

    /**
     * The throttle identity of a session token.
     *
     * @param string $sessionToken Session token as presented by the connection
     * @return ?string Identity to count against, or null when the connection carries no session
     */
    public static function forSession(string $sessionToken): ?string
    {
        return $sessionToken === '' ? null : hash(self::ALGORITHM, $sessionToken);
    }
}

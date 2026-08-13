<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle;

use Hilos\Socket\Client\WebSocketClient;

/**
 * ThrottleIdentity - how a session becomes a throttle key (HIL-420).
 *
 * One recipe in one place, because two of them would be a bug nobody sees: the master
 * composes the identity when it stamps an action ({@see WebSocketClient}) and the session
 * host composes it again when that session finally authenticates, and a counter is only
 * cleared if both arrive at the same string.
 *
 * A digest rather than the token itself, and that is not incidental: the action payload is
 * written to the analytics journal verbatim, so a raw token travelling on it would become a
 * replayable credential sitting in a table. The digest keys the same counter and cannot be
 * presented as a session.
 */
final class ThrottleIdentity
{
    /** Digest the session identity is composed with. */
    private const string ALGORITHM = 'sha256';

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

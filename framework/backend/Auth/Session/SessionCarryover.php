<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

/**
 * SessionCarryover - one live session as it looked before the database was replaced (HIL-479).
 *
 * Everything needed to re-create the row in a database that has never seen it, and nothing
 * else. The lifetime is carried verbatim so a restore neither extends nor shortens what the
 * person already had; the identity pairs are carried because they, not the user id, are what
 * still means something on the other side of the swap. An impersonated session is captured for
 * the real person behind the takeover, so the impersonator marker is not part of the shape.
 */
final class SessionCarryover
{
    /**
     * @param string $token Session cookie token
     * @param int $createdAt Session creation moment in epoch milliseconds
     * @param ?int $expiresAt Session expiry in epoch milliseconds, or null for an open-ended session
     * @param list<SessionIdentityRef> $identities Identity pairs of the person the session belongs to
     */
    public function __construct(
        public readonly string $token,
        public readonly int $createdAt,
        public readonly ?int $expiresAt,
        public readonly array $identities,
    ) {
    }
}

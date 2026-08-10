<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosConnection;

/**
 * Inheritable runtime collection of WebSocket connection rows (HIL-361).
 *
 * The framework-owned base of the connections runtime state: it holds the
 * session-scoped lookups the authenticate/deauthenticate seam needs, keyed off
 * the {@see HilosConnection} session triple. Projects subclass this, set
 * `STATE_CLASS` to their concrete connection, and add their own lookups.
 *
 * @template T of HilosConnection
 * @extends RtStates<T>
 */
abstract class HilosConnections extends RtStates
{
    /**
     * Finds all connections belonging to a session token (indexed by accept key).
     *
     * The authenticate/deauthenticate seam re-points every live connection of a
     * session token when its bound user changes.
     *
     * @param string $sessionToken Session cookie token
     * @return array<string, T> Accept key => connection map (empty for an empty token)
     */
    public function findAllBySessionToken(string $sessionToken): array
    {
        if ($sessionToken === '') {
            return [];
        }

        return array_filter(
            $this->states,
            static fn(HilosConnection $connection): bool => $connection->sessionToken === $sessionToken,
        );
    }

    /**
     * Finds every connection bound to some user (indexed by accept key).
     *
     * The user-agnostic sibling of {@see findByUser()}: the session carry-over
     * (HIL-479) asks "which live connections are logged in at all?" before the
     * database is replaced under the daemon, having no user to name.
     *
     * @return array<string, T> Accept key => connection map (empty when every connection is anonymous)
     */
    public function findAuthenticated(): array
    {
        return array_filter(
            $this->states,
            static fn(HilosConnection $connection): bool => $connection->userId !== null,
        );
    }

    /**
     * Finds all connections bound to a user (indexed by accept key).
     *
     * @param ?int $userId User id, or null for no connections
     * @return array<string, T> Accept key => connection map (empty for a null user)
     */
    public function findByUser(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return array_filter(
            $this->states,
            static fn(HilosConnection $connection): bool => $connection->userId === $userId,
        );
    }
}

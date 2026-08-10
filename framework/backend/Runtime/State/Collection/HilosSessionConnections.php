<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosSessionConnection;

/**
 * Inheritable runtime collection of WebSocket connection rows — the session stage (HIL-509).
 *
 * The stage above {@see HilosConnections}: it adds the session-scoped lookup the
 * authenticate/deauthenticate seam needs, which re-points every live connection
 * of a session token when its bound user changes. A project that carries no
 * browser sessions stays on the presence stage and does not get this method at
 * all — the absence is visible to the type checker rather than at the moment an
 * honest lookup answers empty.
 *
 * @template T of HilosSessionConnection
 * @extends HilosConnections<T>
 */
abstract class HilosSessionConnections extends HilosConnections
{
    /**
     * Finds all connections belonging to a session token (indexed by accept key).
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
            static fn(HilosSessionConnection $connection): bool => $connection->sessionToken === $sessionToken,
        );
    }
}

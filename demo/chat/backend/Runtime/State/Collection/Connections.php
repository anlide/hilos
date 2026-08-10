<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use OutOfBoundsException;

/**
 * Connections - Stores all active WebSocket connections.
 *
 * This is the single source of truth for connection data.
 * RtCollection wrappers provide read-only access. Stands on the framework
 * {@see HilosSessionConnections} base — the session stage, matching the session
 * stage of the row itself — which provides the lookups the presence and the
 * authenticate/deauthenticate seams need (findAuthenticated / findByUser /
 * findAllBySessionToken).
 *
 * @extends HilosSessionConnections<Connection>
 */
final class Connections extends HilosSessionConnections
{
    public const string STATE_CLASS = Connection::class;

    /**
     * @param ?string $id Accept key, or null for a missing optional runtime key
     * @return ?Connection Connection runtime state, or null when missing
     */
    public function get(?string $id): ?Connection
    {
        /** @var ?Connection $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid.
     *
     * @param mixed $offset Accept key
     * @return Connection Connection runtime state
     */
    public function offsetGet(mixed $offset): Connection
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Connection runtime state not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Connection runtime state not found: {$offset}");
    }
}

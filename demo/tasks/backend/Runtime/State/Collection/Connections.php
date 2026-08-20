<?php

declare(strict_types=1);

namespace Demo\Tasks\Runtime\State\Collection;

use Demo\Tasks\Runtime\State\Item\Connection;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use OutOfBoundsException;

/**
 * Connections - the single source of truth for active WebSocket connections.
 *
 * Stands on the framework {@see HilosSessionConnections} base — the session
 * stage, matching the stage of the row itself — which provides the user-scoped
 * lookups (findAuthenticated / findByUser) and the token-scoped one that turns a
 * set of live sockets into the sockets of one browser session. RtCollection
 * wrappers provide read-only access.
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

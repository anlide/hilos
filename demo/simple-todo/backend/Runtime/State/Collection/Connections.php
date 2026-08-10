<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Runtime\State\Collection;

use Demo\SimpleTodo\Runtime\State\Item\Connection;
use Hilos\Runtime\State\Collection\HilosConnections;
use OutOfBoundsException;

/**
 * Connections - the single source of truth for active WebSocket connections.
 *
 * Stands on the framework {@see HilosConnections} base — the presence stage,
 * matching the stage of the row itself — which provides the user-scoped lookups
 * (findAuthenticated / findByUser). RtCollection wrappers provide read-only
 * access.
 *
 * @extends HilosConnections<Connection>
 */
final class Connections extends HilosConnections
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

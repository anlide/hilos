<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Runtime\State\Collection\RtStates;

/**
 * Connections - Stores all active WebSocket connections.
 *
 * This is the single source of truth for connection data.
 * RtCollection wrappers provide read-only access.
 *
 * @extends RtStates<Connection>
 */
final class Connections extends RtStates
{
    public const string STATE_CLASS = Connection::class;

    /**
     * Finds all connections for given user (indexed by accept key).
     *
     * @param int $userId User ID
     * @return array<string, Connection> Accept key => Connection map
     */
    public function findAllByUserId(int $userId): array
    {
        return array_filter($this->states, function ($connection) use ($userId) {
            return $connection->userId === $userId;
        });
    }
}

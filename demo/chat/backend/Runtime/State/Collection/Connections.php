<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Runtime\State\Collection\RtStates;

/**
 * Connections state collection - stores all active WebSocket connections
 *
 * This is the single source of truth for connection data.
 * RtCollection wrappers provide read-only access.
 *
 * @extends RtStates<Connection>
 */
class Connections extends RtStates
{
    public const string STATE_CLASS = Connection::class;

    /**
     * Find first connection for given user
     *
     * @param int $userId User ID
     * @return ?Connection Connection or null
     */
    public function findByUserId(int $userId): ?Connection
    {
        foreach ($this->states as $connection) {
            if ($connection->userId === $userId) {
                return $connection;
            }
        }
        return null;
    }

    /**
     * Find all connections for given user (indexed by accept key)
     *
     * @param int $userId User ID
     * @return array<string, Connection> Accept key => Connection map
     */
    public function findAllByUserId(int $userId): array
    {
        $result = [];
        foreach ($this->states as $acceptKey => $connection) {
            if ($connection->userId === $userId) {
                $result[$acceptKey] = $connection;
            }
        }
        return $result;
    }

    /**
     * Check if user has any active connections
     *
     * @param int $userId User ID
     * @return bool True if at least one connection exists
     */
    public function hasUserConnections(int $userId): bool
    {
        return $this->findByUserId($userId) !== null;
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State;

use Hilos\Runtime\State\RtStates;

/**
 * Connections state collection - stores all active WebSocket connections
 *
 * This is the single source of truth for connection data.
 * IdeaRtCollection wrappers provide read-only access.
 *
 * @extends RtStates<Connection>
 */
class Connections extends RtStates
{
    /**
     * Find connection by userId
     *
     * @param int $userId User ID
     * @return ?Connection
     */
    public function findByUserId(int $userId): ?Connection
    {
        foreach ($this->states as $connection) {
            if ($connection->getUserId() === $userId) {
                return $connection;
            }
        }
        return null;
    }

    /**
     * Get all connections for a user
     *
     * @param int $userId User ID
     * @return array<string, Connection> acceptKey => Connection
     */
    public function findAllByUserId(int $userId): array
    {
        $result = [];
        foreach ($this->states as $acceptKey => $connection) {
            if ($connection->getUserId() === $userId) {
                $result[$acceptKey] = $connection;
            }
        }
        return $result;
    }

    /**
     * Check if user has any active connections
     *
     * @param int $userId User ID
     * @return bool
     */
    public function hasUserConnections(int $userId): bool
    {
        return $this->findByUserId($userId) !== null;
    }
}

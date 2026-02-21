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
    public function findByUserId(int $userId): ?Connection
    {
        foreach ($this->states as $connection) {
            if ($connection->userId === $userId) {
                return $connection;
            }
        }
        return null;
    }

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

    public function hasUserConnections(int $userId): bool
    {
        return $this->findByUserId($userId) !== null;
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\Idea;

use Demo\Chat\Runtime\IdeaActions\ConnectionsActions;
use Demo\Chat\Runtime\State\Connection as StateConnection;
use Demo\Chat\Runtime\State\Connections as StateConnections;
use Hilos\Runtime\Idea\IdeaRtCollection;
use Hilos\Runtime\Idea\IdeaRtItem;
use Hilos\Runtime\State\RtState;

/**
 * Connections Idea collection - read-only wrapper around Connections state
 *
 * Provides high-level access to connection data.
 * Write operations go through ConnectionsActions.
 *
 * @extends IdeaRtCollection<Connection>
 * @property-read ConnectionsActions $actions Actions for write operations
 */
class Connections extends IdeaRtCollection
{
    /**
     * Create IdeaRtItem from RtState
     *
     * @param RtState $state StateConnection instance
     * @return IdeaRtItem Connection instance
     */
    protected function createRtItem(RtState &$state): IdeaRtItem
    {
        /** @var StateConnection $state */
        return new Connection($state);
    }

    /**
     * Find connection by userId
     *
     * @param int $userId User ID
     * @return ?Connection
     */
    public function findByUserId(int $userId): ?Connection
    {
        /** @var ?StateConnections $stateCollection */
        $stateCollection = $this->getStateCollection();
        if ($stateCollection === null) {
            return null;
        }

        $state = $stateCollection->findByUserId($userId);
        if ($state === null) {
            return null;
        }

        return $this->getRtItemForKey($state->getId());
    }

    /**
     * Get user ID by accept key
     *
     * Convenience method for common lookup pattern.
     *
     * @param string $acceptKey WebSocket accept key
     * @return ?int User ID or null if not found
     */
    public function getUserId(string $acceptKey): ?int
    {
        $connection = $this[$acceptKey];
        return $connection?->userId;
    }

    /**
     * Check if user has any active connections
     *
     * @param int $userId User ID
     * @return bool
     */
    public function hasUserConnections(int $userId): bool
    {
        /** @var ?StateConnections $stateCollection */
        $stateCollection = $this->getStateCollection();
        if ($stateCollection === null) {
            return false;
        }

        return $stateCollection->hasUserConnections($userId);
    }
}

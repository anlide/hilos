<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\Idea;

use Demo\Chat\Database\Idea;
use Demo\Chat\Database\Idea\User as IdeaUser;
use Demo\Chat\Database\IdeaCollection\Users as IdeaUsers;
use Demo\Chat\Runtime\IdeaActions\ConnectionsActions;
use Demo\Chat\Runtime\State\Connection as StateConnection;
use Demo\Chat\Runtime\State\Connections as StateConnections;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Collection\IdeaCollectionNotManualException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionActionsClassException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionPropertyNotFoundException;
use Hilos\Runtime\Idea\IdeaRtActions;
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
 * @property-read IdeaUsers $relevantUsers Users who are online or mentioned in events
 */
class Connections extends IdeaRtCollection
{
    public const string relevantUsers = 'relevantUsers';

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

    /**
     * Get IdeaUsers collection containing users who are online (in connections)
     * or mentioned in at least one event.
     *
     * @return IdeaUsers Manual collection of IdeaUser instances
     * @throws IdeaCollectionNotManualException If collection is not manual, or item has no ID
     * @throws DatabaseException If IdeaItem has no associated Object ID
     */
    public function getRelevantUsers(): IdeaUsers
    {
        $userIds = [];

        foreach ($this as $connection) {
            $userIds[$connection->userId] = true;
        }
        foreach (Idea::$db->events as $event) {
            if ($event->userId !== null) {
                $userIds[$event->userId] = true;
            }
        }

        $collection = IdeaUsers::initEmpty();
        foreach (array_keys($userIds) as $userId) {
            if (isset(Idea::$db->users[$userId])) {
                $collection->add(Idea::$db->users[$userId]);
            }
        }

        return $collection;
    }

    /**
     * Magic getter for actions and relevantUsers
     *
     * @param string $name Property name
     * @return IdeaUsers|IdeaRtActions
     * @throws IdeaCollectionNotManualException If collection is not manual, or item has no ID
     * @throws DatabaseException If IdeaItem has no associated Object ID
     * @throws IdeaRtCollectionPropertyNotFoundException
     * @throws IdeaRtCollectionActionsClassException
     */
    public function __get(string $name)
    {
        return match ($name) {
            self::relevantUsers => $this->getRelevantUsers(),
            default => parent::__get($name),
        };
    }
}

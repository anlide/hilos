<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\Idea;

use Demo\Chat\Hilos\Database\Hilos;
use Demo\Chat\Database\IdeaCollection\Users as IdeaUsers;
use Demo\Chat\Runtime\IdeaActions\ConnectionsActions;
use Demo\Chat\Runtime\State\Connection as StateConnection;
use Demo\Chat\Runtime\State\Connections as StateConnections;
use Hilos\Hilos\Runtime\RtActions;
use Hilos\Hilos\Runtime\RtCollection;
use Hilos\Hilos\Runtime\RtItem;
use Hilos\Exception\DatabaseException;
use Hilos\Exception\Idea\Collection\IdeaCollectionNotManualException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionActionsClassException;
use Hilos\Exception\Runtime\Collection\IdeaRtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\RtState;

/**
 * Connections Idea collection - read-only wrapper around Connections state
 * @deprecated Use Demo\Chat\Hilos\Runtime\Rt\Connections.
 *
 * Provides high-level access to connection data.
 * Write operations go through ConnectionsActions.
 *
 * @extends RtCollection<Connection>
 * @property-read ConnectionsActions $actions Actions for write operations
 * @property-read IdeaUsers $relevantUsers Users who are online or mentioned in events
 */
class Connections extends RtCollection
{
    public const string relevantUsers = 'relevantUsers';

    /**
     * State is always StateConnections (set in setRepresent / forUser).
     *
     * @return StateConnections
     */
    public function getStateCollection(): StateConnections
    {
        /** @var StateConnections */
        return parent::getStateCollection();
    }

    /**
     * Get connections for a specific user
     *
     * Uses State\Connections::findAllByUserId to get original state objects,
     * then creates a filtered Idea\Connections wrapping references to those originals.
     *
     * @param int $userId User ID
     * @return self Connections collection filtered by user
     */
    public function forUser(int $userId): self
    {
        $stateConnections = $this->getStateCollection();

        $filteredState = StateConnections::init();
        foreach ($stateConnections->findAllByUserId($userId) as $stateConnection) {
            $filteredState->add($stateConnection);
        }

        $collection = self::init();
        $collection->setStateCollection($filteredState);
        return $collection;
    }

    /**
     * Create IdeaRtItem from RtState
     *
     * @param RtState $state StateConnection instance
     * @return RtItem Connection instance
     */
    protected function createRtItem(RtState &$state): RtItem
    {
        /** @var StateConnection $state */
        return new Connection($state);
    }

    /**
     * @param mixed $offset
     * @return ?Connection
     */
    public function offsetGet(mixed $offset): ?Connection
    {
        return parent::offsetGet($offset);
    }

    /**
     * @return ?Connection
     */
    public function first(): ?Connection
    {
        return parent::first();
    }

    /**
     * @return ?Connection
     */
    public function last(): ?Connection
    {
        return parent::last();
    }

    /**
     * @return ?Connection
     */
    public function current(): ?Connection
    {
        return parent::current();
    }

    /**
     * @param string $key
     * @return ?Connection
     */
    protected function getRtItemForKey(string $key): ?Connection
    {
        return parent::getRtItemForKey($key);
    }

    /**
     * @return ConnectionsActions
     * @throws IdeaRtCollectionActionsClassException
     */
    protected function getActions(): ConnectionsActions
    {
        return parent::getActions();
    }

    /**
     * Get IdeaUsers collection containing users who are online (in connections)
     * or mentioned in at least one event.
     *
     * @return IdeaUsers Manual collection of IdeaUser instances
     * @throws IdeaCollectionNotManualException If collection is not manual, or item has no ID
     * @throws DatabaseException If IdeaItem has no associated Object ID
     */
    private function getRelevantUsers(): IdeaUsers
    {
        $userIds = [];

        foreach ($this as $connection) {
            $userIds[$connection->userId] = true;
        }
        foreach (Hilos::$db->events as $event) {
            if ($event->userId !== null) {
                $userIds[$event->userId] = true;
            }
        }

        $collection = IdeaUsers::initEmpty();
        foreach (array_keys($userIds) as $userId) {
            if (isset(Hilos::$db->users[$userId])) {
                $collection->add(Hilos::$db->users[$userId]);
            }
        }

        return $collection;
    }

    /**
     * Magic getter for actions and relevantUsers
     *
     * @param string $name Property name
     * @return ConnectionsActions|IdeaUsers
     * @throws IdeaCollectionNotManualException If collection is not manual, or item has no ID
     * @throws DatabaseException If IdeaItem has no associated Object ID
     * @throws IdeaRtCollectionPropertyNotFoundException
     * @throws IdeaRtCollectionActionsClassException
     */
    public function __get(string $name): ConnectionsActions|IdeaUsers
    {
        return match ($name) {
            self::actions => $this->getActions(),
            self::relevantUsers => $this->getRelevantUsers(),
            default => parent::__get($name),
        };
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Hilos\Runtime\Rt;

use Demo\Chat\Hilos\Database\Hilos;
use Demo\Chat\Hilos\Database\DbCollection\Users as DbUsers;
use Demo\Chat\Hilos\Runtime\RtActions\ConnectionsActions;
use Demo\Chat\Runtime\State\Connection as StateConnection;
use Demo\Chat\Runtime\State\Connections as StateConnections;
use Hilos\Hilos\Runtime\RtCollection;
use Hilos\Hilos\Runtime\RtItem;
use Hilos\Runtime\State\RtState;

/**
 * Connections Rt collection - read-only wrapper around Connections state.
 *
 * Provides high-level access to connection data.
 * Write operations go through ConnectionsActions.
 *
 * @extends RtCollection<Connection>
 * @property-read ConnectionsActions $actions Actions for write operations
 * @property-read DbUsers $relevantUsers Users who are online or mentioned in events
 */
class Connections extends RtCollection
{
    public const string relevantUsers = 'relevantUsers';

    /** @return StateConnections */
    public function getStateCollection(): StateConnections
    {
        /** @var StateConnections */
        return parent::getStateCollection();
    }

    /**
     * Get connections for a specific user.
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
     * Create Rt item from state.
     *
     * @param RtState $state StateConnection instance
     * @return RtItem Connection instance
     */
    protected function createRtItem(RtState &$state): RtItem
    {
        /** @var StateConnection $state */
        return new Connection($state);
    }

    public function offsetGet(mixed $offset): ?Connection
    {
        return parent::offsetGet($offset);
    }

    public function first(): ?Connection
    {
        return parent::first();
    }

    public function last(): ?Connection
    {
        return parent::last();
    }

    public function current(): ?Connection
    {
        return parent::current();
    }

    protected function getRtItemForKey(string $key): ?Connection
    {
        return parent::getRtItemForKey($key);
    }

    protected function getActions(): ConnectionsActions
    {
        return parent::getActions();
    }

    private function getRelevantUsers(): DbUsers
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

        $collection = DbUsers::initEmpty();
        foreach (array_keys($userIds) as $userId) {
            if (isset(Hilos::$db->users[$userId])) {
                $collection->add(Hilos::$db->users[$userId]);
            }
        }

        return $collection;
    }

    public function __get(string $name): ConnectionsActions|DbUsers
    {
        return match ($name) {
            self::actions => $this->getActions(),
            self::relevantUsers => $this->getRelevantUsers(),
            default => parent::__get($name),
        };
    }
}

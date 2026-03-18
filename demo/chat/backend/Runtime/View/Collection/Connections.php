<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Database\View\Collection\Users as DbUsers;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Collection\Connections as StateConnections;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Actions\ConnectionsActions;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Connections - Read-only wrapper around Connections state.
 *
 * Provides high-level access to connection data.
 * Write operations go through ConnectionsActions.
 *
 * @extends RtCollection<Connection>
 * @property-read ConnectionsActions $actions Actions for write operations
 * @property-read DbUsers $relevantUsers Users who are online or mentioned in events
 */
final class Connections extends RtCollection
{
    public const string relevantUsers = 'relevantUsers';

    /**
     * Get underlying state collection.
     *
     * @return StateConnections State collection instance
     * @throws RtActionsStateCollectionNullException If state collection is null (should not happen in properly initialized collection)
     */
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
     * @throws RtActionsStateCollectionNullException If state collection is null (should not happen in properly initialized collection)
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
     * @param RtState $state StateConnection instance (passed by reference)
     * @return RtItem Connection Rt item instance
     */
    protected function createRtItem(RtState &$state): RtItem
    {
        /** @var StateConnection $state */
        return new Connection($state);
    }

    /**
     * Get connection by offset (accept key).
     *
     * @param mixed $offset Accept key (string)
     * @return ?Connection Connection or null if not found
     */
    public function offsetGet(mixed $offset): ?Connection
    {
        return parent::offsetGet($offset);
    }

    /**
     * Get first connection in collection.
     *
     * @return ?Connection First connection or null if empty
     */
    public function first(): ?Connection
    {
        return parent::first();
    }

    /**
     * Get last connection in collection.
     *
     * @return ?Connection Last connection or null if empty
     */
    public function last(): ?Connection
    {
        return parent::last();
    }

    /**
     * Get current connection in iteration.
     *
     * @return ?Connection Current connection or null
     */
    public function current(): ?Connection
    {
        return parent::current();
    }

    /**
     * Get connection by key (accept key).
     *
     * @param string $key Accept key
     * @return ?Connection Connection or null if not found
     */
    protected function getRtItemForKey(string $key): ?Connection
    {
        return parent::getRtItemForKey($key);
    }

    /**
     * Get connections actions instance.
     *
     * @return ConnectionsActions Actions for write operations
     */
    protected function getActions(): ConnectionsActions
    {
        return parent::getActions();
    }

    /**
     * Get users who are online or mentioned in events.
     *
     * @return DbUsers Users collection with relevant users
     */
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

    /**
     * Property getter (actions or relevantUsers).
     *
     * @param string $name Property name (actions, relevantUsers)
     * @return ConnectionsActions|DbUsers Actions or relevant users collection
     * @throws RtCollectionPropertyNotFoundException If property name is not recognized
     */
    public function __get(string $name): ConnectionsActions|DbUsers
    {
        return match ($name) {
            self::actions => $this->getActions(),
            self::relevantUsers => $this->getRelevantUsers(),
            default => parent::__get($name),
        };
    }
}

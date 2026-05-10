<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Database\View\Collection\Users as DbUsers;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\DTO\UserConnectionSummary;
use Demo\Chat\Runtime\State\Collection\Connections as StateConnections;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;

/**
 * Connections - Read-only wrapper around Connections state.
 *
 * Provides high-level access to connection data.
 * Write operations go through ConnectionsActions.
 *
 * @extends RtCollection<Connection, ConnectionsActions>
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
     * @param ?int $userId User ID, or null for an empty result
     * @return self Connections collection filtered by user
     * @throws RtActionsStateCollectionNullException If state collection is null (should not happen in properly initialized collection)
     */
    public function forUser(?int $userId): self
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
     * Builds the runtime connection summary used by user-facing table rows.
     *
     * @param ?int $userId User id to summarize active runtime connections for
     * @return UserConnectionSummary Runtime presence and session count summary
     */
    public function summaryForUser(?int $userId): UserConnectionSummary
    {
        return new UserConnectionSummary(count($this->forUser($userId)));
    }

    /**
     * Sum of (declaredSize - receivedBytes) over connections with an active file upload session (quota reserved until bytes arrive).
     *
     * @return int Non-negative sum of unreceived bytes for in-flight uploads.
     */
    public function sumActiveUploadReservedBytes(): int
    {
        $sum = 0;
        foreach ($this as $c) {
            if ($c->fileSessionUploadId === null) {
                continue;
            }
            $sum += (int)$c->fileSessionDeclaredSize - (int)$c->fileSessionReceivedBytes;
        }

        return $sum;
    }

    /**
     * Check whether an active upload already uses the normalized filename.
     *
     * @param string $normalized Normalized basename
     * @return bool True when any connection has an in-flight upload with that name
     */
    public function hasActiveUploadWithNormalizedFilename(string $normalized): bool
    {
        foreach ($this as $connection) {
            if ($connection->fileSessionUploadId === null) {
                continue;
            }
            if ($connection->fileSessionNormalizedFilename === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create Rt item from state.
     *
     * @param RtState $state StateConnection instance (passed by reference)
     * @return Connection View item for this connection state
     */
    protected function createRtItem(RtState &$state): Connection
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
        /** @var ?Connection $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * Get first connection in collection.
     *
     * @return ?Connection First connection or null if empty
     */
    public function first(): ?Connection
    {
        /** @var ?Connection $item */
        $item = parent::first();

        return $item;
    }

    /**
     * Get last connection in collection.
     *
     * @return ?Connection Last connection or null if empty
     */
    public function last(): ?Connection
    {
        /** @var ?Connection $item */
        $item = parent::last();

        return $item;
    }

    /**
     * Get current connection in iteration.
     *
     * @return ?Connection Current connection or null
     */
    public function current(): ?Connection
    {
        /** @var ?Connection $item */
        $item = parent::current();

        return $item;
    }

    /**
     * Get connection by key (accept key).
     *
     * @param string $key Accept key
     * @return ?Connection Connection or null if not found
     */
    protected function getRtItemForKey(string $key): ?Connection
    {
        /** @var ?Connection $item */
        $item = parent::getRtItemForKey($key);

        return $item;
    }

    /**
     * Get connections actions instance.
     *
     * @return ConnectionsActions Actions for write operations
     * @throws RtCollectionActionsClassException
     */
    protected function getActions(): ConnectionsActions
    {
        /** @var ConnectionsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * Get users who are online or mentioned in events.
     *
     * @return DbUsers Users collection with relevant users
     * @throws CollectionNotManualException If collection is not manual, or item has no ID
     * @throws ObjectGetIdStringNotImplementedException If DbItem's Object does not implement getIdString() (required for manual collections to use ID as key)
     */
    private function getRelevantUsers(): DbUsers
    {
        $userIds = [];
        foreach ($this as $connection) {
            $userIds[$connection->userId] = true;
        }
        foreach (Hilos::$db->events as $event) {
            if ($event->authorUserId !== null) {
                $userIds[$event->authorUserId] = true;
            }
            if ($event->targetUserId !== null) {
                $userIds[$event->targetUserId] = true;
            }
            if ($event->actorUserId !== null) {
                $userIds[$event->actorUserId] = true;
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
     * @throws RtCollectionActionsClassException
     * @throws CollectionNotManualException If collection is not manual, or item has no ID
     * @throws ObjectGetIdStringNotImplementedException If DbItem's Object does not implement getIdString() (required for manual collections to use ID as key)
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

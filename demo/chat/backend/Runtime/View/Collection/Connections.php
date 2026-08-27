<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Database\View\Collection\Users as DbUsers;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\HilosSessionConnections;

/**
 * Connections - Read-only wrapper around Connections state.
 *
 * Stands on the framework {@see HilosSessionConnections} base — the session stage
 * — which carries the user-scoped reads, the presence source the users table
 * merges over its rows, and the session-token lookup the session host seam makes.
 * What is left here is chat's own: the upload quota reads and the users a client
 * needs rows for. Write operations go through ConnectionsActions.
 *
 * @extends HilosSessionConnections<Connection, ConnectionsActions>
 * @property-read ConnectionsActions $actions Actions for write operations
 * @property-read DbUsers $relevantUsers Users who are online or mentioned in events
 */
final class Connections extends HilosSessionConnections
{
    public const string relevantUsers = 'relevantUsers';

    /**
     * Sum of unreceived bytes across active file upload sessions.
     *
     * The quota is reserved until bytes arrive.
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
     * @param RtState $state StateConnection instance
     * @return Connection View item for this connection state
     */
    protected function createRtItem(RtState $state): Connection
    {
        /** @var StateConnection $state */
        return new Connection($state);
    }

    /**
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
     * @return ConnectionsActions Actions for write operations
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
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
     * @throws CollectionNotManualException When relevant users cannot be added to a manual collection
     * @throws ObjectGetIdStringNotImplementedException When a DB user item cannot expose a manual collection key
     */
    private function getRelevantUsers(): DbUsers
    {
        $userIds = [];
        foreach ($this as $connection) {
            if ($connection->userId !== null) {
                $userIds[$connection->userId] = true;
            }
        }
        foreach (Hilos::$db->events as $event) {
            $eventMessage = $event->eventMessage;
            if ($eventMessage?->authorUserId !== null) {
                $userIds[$eventMessage->authorUserId] = true;
            }
            $eventUserRegistration = $event->eventUserRegistration;
            if ($eventUserRegistration?->targetUserId !== null) {
                $userIds[$eventUserRegistration->targetUserId] = true;
            }
            $eventUserRename = $event->eventUserRename;
            if ($eventUserRename?->targetUserId !== null) {
                $userIds[$eventUserRename->targetUserId] = true;
            }
            if ($eventUserRename?->actorUserId !== null) {
                $userIds[$eventUserRename->actorUserId] = true;
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
     * Resolves collection actions and the computed relevant users collection.
     *
     * @param string $name Property name (actions, relevantUsers)
     * @return ConnectionsActions|DbUsers Actions or relevant users collection
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws CollectionNotManualException When relevant users cannot be added to a manual collection
     * @throws ObjectGetIdStringNotImplementedException When a DB user item cannot expose a manual collection key
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

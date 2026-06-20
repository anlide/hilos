<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Runtime\View\Collection;

use Demo\SimpleTodo\Runtime\State\Collection\Connections as StateConnections;
use Demo\SimpleTodo\Runtime\State\Item\Connection as StateConnection;
use Demo\SimpleTodo\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\SimpleTodo\Runtime\View\Item\Connection;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * Connections - read-only wrapper around the connections runtime state.
 *
 * Serves as the project's presence source: the framework users table merges
 * each user's online session count and presence through summaryForUser().
 * Write operations go through ConnectionsActions.
 *
 * @extends RtCollection<Connection, ConnectionsActions>
 * @property-read ConnectionsActions $actions Actions for write operations
 */
final class Connections extends RtCollection implements HilosPresenceSource
{
    /**
     * @return StateConnections State collection instance
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function getStateCollection(): StateConnections
    {
        /** @var StateConnections */
        return parent::getStateCollection();
    }

    /**
     * Get connections for a specific user.
     *
     * @param ?int $userId User id, or null for an empty result
     * @return self Connections collection filtered by user
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
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
     * Builds the runtime presence summary used by user-facing table rows.
     *
     * @param ?int $userId User id to summarize active runtime connections for
     * @return HilosUserPresenceSummary Runtime presence and session count summary
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function summaryForUser(?int $userId): HilosUserPresenceSummary
    {
        return new HilosUserPresenceSummary(count($this->forUser($userId)));
    }

    /**
     * @param RtState $state StateConnection instance (passed by reference)
     * @return Connection View item for this connection state
     */
    protected function createRtItem(RtState &$state): Connection
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
     * @return ?Connection First connection or null if empty
     */
    public function first(): ?Connection
    {
        /** @var ?Connection $item */
        $item = parent::first();

        return $item;
    }

    /**
     * @return ?Connection Last connection or null if empty
     */
    public function last(): ?Connection
    {
        /** @var ?Connection $item */
        $item = parent::last();

        return $item;
    }

    /**
     * @return ?Connection Current connection or null
     */
    public function current(): ?Connection
    {
        /** @var ?Connection $item */
        $item = parent::current();

        return $item;
    }

    /**
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
     * @return ConnectionsActions Actions for write operations
     * @throws RtCollectionActionsClassException When the actions class is missing or invalid
     */
    protected function getActions(): ConnectionsActions
    {
        /** @var ConnectionsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * Resolves collection actions.
     *
     * @param string $name Property name (actions)
     * @return ConnectionsActions Actions for write operations
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When the actions class is missing or invalid
     */
    public function __get(string $name): ConnectionsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}

<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Runtime\View\Collection;

use Demo\EcommerceShop\Runtime\State\Item\Connection as StateConnection;
use Demo\EcommerceShop\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\EcommerceShop\Runtime\View\Item\Connection;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\HilosSessionConnections;

/**
 * Connections - read-only wrapper around the connections runtime state.
 *
 * Stands on the framework {@see HilosSessionConnections} base — the session stage —
 * which carries the user-scoped reads and the token-scoped read the session seam finds
 * a session's live sockets by. This demo adds nothing but the types: which item its
 * rows are seen as, and which actions write them.
 *
 * @extends HilosSessionConnections<Connection, ConnectionsActions>
 * @property-read ConnectionsActions $actions Actions for write operations
 */
final class Connections extends HilosSessionConnections
{
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
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?Connection
    {
        /** @var ?Connection $item */
        $item = parent::offsetGet($offset);

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
     * @throws HilosException Whatever the base collection's own case raises
     */
    public function __get(string $name): ConnectionsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}

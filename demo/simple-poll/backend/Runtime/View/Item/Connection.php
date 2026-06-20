<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Runtime\View\Item;

use Demo\SimplePoll\Runtime\State\Item\Connection as StateConnection;
use Demo\SimplePoll\Runtime\View\Actions\Item\ConnectionActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only runtime item for one connection state row.
 *
 * Minimal presence core: exposes the accept key, the owning user id, and the
 * per-connection write actions (unregister).
 *
 * @extends RtItem<StateConnection>
 *
 * @property-read string $acceptKey WebSocket accept key
 * @property-read int $userId User id for this connection
 * @property-read ConnectionActions $actions Write operations for this connection
 */
final class Connection extends RtItem
{
    /**
     * @param StateConnection $state Backing state (by reference, same as parent contract)
     */
    public function __construct(StateConnection &$state)
    {
        parent::__construct($state);
    }

    /**
     * Delegates known keys to the backing state and resolves item actions.
     *
     * @param string $name Property name (acceptKey, userId, actions)
     * @return string|int|ConnectionActions Property value or item actions
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|int|ConnectionActions
    {
        return match ($name) {
            StateConnection::acceptKey => $this->_state->acceptKey,
            StateConnection::userId => $this->_state->userId,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

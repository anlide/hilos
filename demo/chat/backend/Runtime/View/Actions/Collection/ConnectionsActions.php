<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;

/**
 * Write API for the active WebSocket connections runtime collection.
 *
 * Registering a socket of a session and clearing the collection are the
 * framework's own writes, and chat adds none of its own at the collection level:
 * its per-socket moderation state goes through connection item actions.
 *
 * @extends HilosSessionConnectionsActions<RuntimeConnection, Connections>
 */
final class ConnectionsActions extends HilosSessionConnectionsActions
{
}

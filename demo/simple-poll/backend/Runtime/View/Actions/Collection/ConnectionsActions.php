<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Runtime\View\Actions\Collection;

use Demo\SimplePoll\Runtime\View\Collection\Connections;
use Demo\SimplePoll\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;

/**
 * Write API for the active WebSocket connections runtime collection.
 *
 * Nothing of this demo's own: registering a socket and clearing the collection
 * are the framework's own writes, and this demo makes no others.
 *
 * @extends HilosConnectionsActions<RuntimeConnection, Connections>
 */
final class ConnectionsActions extends HilosConnectionsActions
{
}

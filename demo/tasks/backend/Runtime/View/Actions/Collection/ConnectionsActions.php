<?php

declare(strict_types=1);

namespace Demo\Tasks\Runtime\View\Actions\Collection;

use Demo\Tasks\Runtime\View\Collection\Connections;
use Demo\Tasks\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;

/**
 * Write API for the active WebSocket connections runtime collection.
 *
 * Nothing of this demo's own: registering a socket under its session, moving a
 * row onto a renamed session and marking the ack a socket owes are the
 * framework's own writes, and this demo makes no others.
 *
 * @extends HilosSessionConnectionsActions<RuntimeConnection, Connections>
 */
final class ConnectionsActions extends HilosSessionConnectionsActions
{
}

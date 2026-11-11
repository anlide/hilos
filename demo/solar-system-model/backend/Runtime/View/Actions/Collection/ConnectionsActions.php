<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Runtime\View\Actions\Collection;

use Demo\SolarSystemModel\Runtime\State\Collection\Connections as StateConnections;
use Demo\SolarSystemModel\Runtime\View\Collection\Connections;
use Demo\SolarSystemModel\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;

/**
 * Write API for the active WebSocket connections runtime collection.
 *
 * Nothing of this demo's own: registering a socket under its session, moving a
 * row onto a renamed session and marking the ack a socket owes are the
 * framework's own writes, and this demo makes no others.
 *
 * @extends HilosSessionConnectionsActions<RuntimeConnection, Connections>
 * @property-read StateConnections $stateCollection Backing connection states
 */
final class ConnectionsActions extends HilosSessionConnectionsActions
{
}

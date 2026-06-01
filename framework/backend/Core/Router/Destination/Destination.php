<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

/**
 * Destination - Marker interface for a resolved signal delivery target.
 *
 * SignalRouter::getDestinations() returns a list of these; DaemonManager
 * dispatches each by concrete type: AgentDestination is delivered to an agent
 * through the worker server, WebSocketDestination to a single WebSocket client.
 *
 * Destinations are computed and consumed inside the daemon process and never
 * cross a process boundary, so they are plain value objects without a transport
 * array shape.
 */
interface Destination
{
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime;

use Demo\Chat\Hilos\Runtime\Rt\Connections;
use Demo\Chat\Hilos\Runtime\RtActions\ConnectionsActions;
use Demo\Chat\Runtime\State\Connections as StateConnections;
use Hilos\Exception\Hilos\Runtime\Rt\StateCollectionNotFoundException;
use Hilos\Hilos\Runtime\RtContext;

/**
 * ChatRuntime - application-specific runtime data access
 *
 * Extends framework runtime context to provide chat-specific runtime collections.
 *
 * Available collections:
 *   - connections: Active WebSocket connections (acceptKey → userId mapping)
 *
 * Usage:
 *   Hilos::$rt->connections[$acceptKey];  // Get connection by accept key
 *   Hilos::$rt->connections[$acceptKey]?->userId;  // Get user ID
 *   Hilos::$rt->connections->actions->register($acceptKey, $userId);  // Register connection
 *
 * @property-read Connections $connections Active connections collection
 */
final class ChatRuntime extends RtContext
{
    public const string connections = 'connections';

    /**
     * Initialize runtime with state collections
     *
     * @return static
     * @throws StateCollectionNotFoundException
     */
    public static function init(): static
    {
        $instance = new static();

        // Create state collections
        $instance->_stateCollections[self::connections] = StateConnections::init();

        // Configure runtime collection wrappers
        $instance->setRepresent(self::connections, Connections::class, ConnectionsActions::class);

        return $instance;
    }
}

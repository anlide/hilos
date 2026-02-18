<?php

declare(strict_types=1);

namespace Demo\Chat\Hilos\Runtime\Context;

use Demo\Chat\Hilos\Runtime\Actions\ConnectionsActions;
use Demo\Chat\Hilos\Runtime\Collection\Connections;
use Demo\Chat\Hilos\Runtime\State\Collection\Connections as StateConnections;
use Hilos\Hilos\Runtime\Context\RtContext;
use Hilos\Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;

/**
 * RtChatContext - chat runtime context (runtime data access for the chat app).
 *
 * Available collections:
 *   - connections: Active WebSocket connections (acceptKey → userId mapping)
 *
 * Usage:
 *   Hilos::$rt->connections[$acceptKey];  // Get connection by accept key
 *   Hilos::$rt->connections->actions->register($acceptKey, $userId);  // Register connection
 *
 * @property-read Connections $connections Active connections collection
 */
final class RtChatContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * @throws StateCollectionNotFoundException
     */
    public static function init(): static
    {
        $instance = new static();
        $instance->_stateCollections[self::connections] = StateConnections::init();
        $instance->setRepresent(self::connections, Connections::class, ConnectionsActions::class);
        return $instance;
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Context;

use Demo\Chat\Runtime\State\Collection\Connections as StateConnections;
use Demo\Chat\Runtime\View\Actions\ConnectionsActions;
use Demo\Chat\Runtime\View\Collection\Connections;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\View\Context\RtContext;

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

<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime;

use Demo\Chat\Runtime\Idea\Connections as IdeaConnections;
use Demo\Chat\Runtime\IdeaActions\ConnectionsActions;
use Demo\Chat\Runtime\State\Connections as StateConnections;
use Hilos\Exception\Runtime\Rt\IdeaRtStateCollectionNotFoundException;
use Hilos\Runtime\Idea\IdeaRt;

/**
 * ChatRuntime - application-specific runtime data access
 *
 * Extends framework IdeaRt to provide chat-specific runtime collections.
 *
 * Available collections:
 *   - connections: Active WebSocket connections (acceptKey → userId mapping)
 *
 * Usage:
 *   Idea::$rt->connections[$acceptKey];  // Get connection by accept key
 *   Idea::$rt->connections->getUserId($acceptKey);  // Get user ID
 *   Idea::$rt->connections->actions->register($acceptKey, $userId);  // Register connection
 *
 * @property-read IdeaConnections $connections Active connections collection
 */
final class ChatRuntime extends IdeaRt
{
    public const string connections = 'connections';

    /**
     * Initialize runtime with state collections
     *
     * @return static
     * @throws IdeaRtStateCollectionNotFoundException
     */
    public static function init(): static
    {
        $instance = new static();

        // Create state collections
        $instance->_stateCollections[self::connections] = StateConnections::init();

        // Configure Idea collections
        $instance->setRepresent(self::connections, IdeaConnections::class, ConnectionsActions::class);

        return $instance;
    }
}

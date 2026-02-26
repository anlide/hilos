<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Context;

use Demo\Chat\Runtime\State\Collection\Connections as StateConnections;
use Demo\Chat\Runtime\State\Collection\ModerationStates as StateModerationStates;
use Demo\Chat\Runtime\View\Actions\ConnectionsActions;
use Demo\Chat\Runtime\View\Actions\ModerationStatesActions;
use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Collection\ModerationStates;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\View\Context\RtContext;

/**
 * RtChatContext - chat runtime context (runtime data access for the chat app).
 *
 * Available collections:
 *   - connections: Active WebSocket connections (acceptKey → userId mapping)
 *   - moderationStates: Pending moderation message per user (userId → message)
 *
 * Usage:
 *   Hilos::$rt->connections[$acceptKey];  // Get connection by accept key
 *   Hilos::$rt->connections->actions->register($acceptKey, $userId);  // Register connection
 *
 * @property-read Connections $connections Active connections collection
 * @property-read ModerationStates $moderationStates Pending moderation states collection
 */
final class RtChatContext extends RtContext
{
    // Collections (plural)
    public const string connections = 'connections';
    public const string moderationStates = 'moderationStates';

    // Singular property keys (used in User frontend payload, etc.)
    public const string connection = 'connection';
    public const string moderationState = 'moderationState';

    /**
     * @throws StateCollectionNotFoundException
     */
    public static function init(): static
    {
        $instance = new static();
        $instance->_stateCollections[self::connections] = StateConnections::init();
        $instance->_stateCollections[self::moderationStates] = StateModerationStates::init();
        $instance->setRepresent(self::connections, Connections::class, ConnectionsActions::class);
        $instance->setRepresent(self::moderationStates, ModerationStates::class, ModerationStatesActions::class);
        return $instance;
    }
}

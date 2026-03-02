<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Context;

use Demo\Chat\Runtime\State\Collection\ChatContexts as StateChatContexts;
use Demo\Chat\Runtime\State\Collection\Connections as StateConnections;
use Demo\Chat\Runtime\State\Collection\ModerationStates as StateModerationStates;
use Demo\Chat\Runtime\View\Actions\ChatContextsActions;
use Demo\Chat\Runtime\View\Actions\ConnectionsActions;
use Demo\Chat\Runtime\View\Actions\ModerationStatesActions;
use Demo\Chat\Runtime\View\Collection\ChatContexts;
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
 *   - chatContexts: Shared chat context for bots (topic, summary, online participants)
 *
 * Usage:
 *   Hilos::$rt->connections[$acceptKey];  // Get connection by accept key
 *   Hilos::$rt->connections->actions->register($acceptKey, $userId);  // Register connection
 *   Hilos::$rt->chatContexts[ChatContext::ID_MAIN];  // Get main chat context
 *
 * @property-read Connections $connections Active connections collection
 * @property-read ModerationStates $moderationStates Pending moderation states collection
 * @property-read ChatContexts $chatContexts Chat context collection (singleton key "main")
 */
final class RtChatContext extends RtContext
{
    // Collections (plural)
    public const string connections = 'connections';
    public const string moderationStates = 'moderationStates';
    public const string chatContexts = 'chatContexts';

    // Singular property keys (used in User frontend payload, etc.)
    public const string connection = 'connection';
    public const string moderationState = 'moderationState';
    public const string chatContext = 'chatContext';

    /**
     * @throws StateCollectionNotFoundException
     */
    public static function init(): static
    {
        $instance = new static();
        $instance->_stateCollections[self::connections] = StateConnections::init();
        $instance->_stateCollections[self::moderationStates] = StateModerationStates::init();
        $instance->_stateCollections[self::chatContexts] = StateChatContexts::init();
        $instance->setRepresent(self::connections, Connections::class, ConnectionsActions::class);
        $instance->setRepresent(self::moderationStates, ModerationStates::class, ModerationStatesActions::class);
        $instance->setRepresent(self::chatContexts, ChatContexts::class, ChatContextsActions::class);
        return $instance;
    }
}

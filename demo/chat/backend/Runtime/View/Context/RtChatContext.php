<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Context;

use Demo\Chat\Runtime\State\Collection\ChatContexts as StateChatContexts;
use Demo\Chat\Runtime\State\Collection\Connections as StateConnections;
use Demo\Chat\Runtime\State\Collection\UserStates as StateUserStates;
use Demo\Chat\Runtime\View\Actions\Collection\ChatContextsActions;
use Demo\Chat\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\Chat\Runtime\View\Actions\Collection\UserStatesActions;
use Demo\Chat\Runtime\View\Actions\Item\ConnectionActions;
use Demo\Chat\Runtime\View\Collection\ChatContexts;
use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Collection\UserStates;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\View\Context\RtContext;

/**
 * RtChatContext - chat runtime context (runtime data access for the chat app).
 *
 * Available collections:
 *   - connections: Active WebSocket connections (acceptKey → userId mapping)
 *   - userStates: Per-user runtime (text moderation, file upload UI/session)
 *   - chatContexts: Shared chat context for bots (topic, summary, online participants)
 *
 * Usage:
 *   Hilos::$rt->connections[$acceptKey];
 *   Hilos::$rt->connections->actions->register($acceptKey, $userId);
 *   Hilos::$rt->connections[$acceptKey]->actions->… (per-connection writes, e.g. file upload)
 *   Hilos::$rt->userStates->actions->ensure($userId);
 *   Hilos::$rt->chatContexts[ChatContext::ID_MAIN];
 *
 * @property-read Connections $connections Active connections collection
 * @property-read UserStates $userStates Per-user chat runtime state
 * @property-read ChatContexts $chatContexts Chat context collection (singleton key "main")
 */
final class RtChatContext extends RtContext
{
    public const string connections = 'connections';
    public const string userStates = 'userStates';
    public const string chatContexts = 'chatContexts';

    public const string connection = 'connection';
    public const string chatUserState = 'chatUserState';
    public const string chatContext = 'chatContext';

    /**
     * @return static
     *
     * @throws StateCollectionNotFoundException
     */
    public static function init(): static
    {
        $instance = new static();
        $instance->_stateCollections[self::connections] = StateConnections::init();
        $instance->_stateCollections[self::userStates] = StateUserStates::init();
        $instance->_stateCollections[self::chatContexts] = StateChatContexts::init();
        $instance->setRepresent(self::connections, Connections::class, ConnectionsActions::class, ConnectionActions::class);
        $instance->setRepresent(self::userStates, UserStates::class, UserStatesActions::class);
        $instance->setRepresent(self::chatContexts, ChatContexts::class, ChatContextsActions::class);

        return $instance;
    }
}

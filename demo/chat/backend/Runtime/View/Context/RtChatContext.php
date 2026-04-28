<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Context;

use Demo\Chat\Runtime\State\Collection\ChatContexts as StateChatContexts;
use Demo\Chat\Runtime\State\Collection\Connections as StateConnections;
use Demo\Chat\Runtime\State\Collection\BotAgentStatuses as StateBotAgentStatuses;
use Demo\Chat\Runtime\State\Collection\GuardianAgentStatuses as StateGuardianAgentStatuses;
use Demo\Chat\Runtime\State\Collection\UserStates as StateUserStates;
use Demo\Chat\Runtime\View\Actions\Collection\BotAgentStatusesActions;
use Demo\Chat\Runtime\View\Actions\Collection\ChatContextsActions;
use Demo\Chat\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\Chat\Runtime\View\Actions\Collection\GuardianAgentStatusesActions;
use Demo\Chat\Runtime\View\Actions\Collection\UserStatesActions;
use Demo\Chat\Runtime\View\Actions\Item\ConnectionActions;
use Demo\Chat\Runtime\View\Collection\BotAgentStatuses;
use Demo\Chat\Runtime\View\Collection\ChatContexts;
use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Collection\GuardianAgentStatuses;
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
 *   - botAgentStatuses: Runtime lifecycle markers for bot agents
 *   - guardianAgentStatuses: Runtime UI statuses for Hilos guardian agents
 *
 * Usage:
 *   Hilos::$rt->connections[$acceptKey];
 *   Hilos::$rt->connections->actions->register($acceptKey, $userId);
 *   Hilos::$rt->connections[$acceptKey]->actions->… (per-connection writes, e.g. file upload)
 *   Hilos::$rt->userStates[$userId]; // key is string user id; offsetGet casts int to string
 *   Hilos::$rt->chatContexts[ChatContext::ID_MAIN];
 *
 * @property-read Connections $connections Active connections collection
 * @property-read UserStates $userStates Per-user chat runtime state
 * @property-read ChatContexts $chatContexts Chat context collection (singleton key "main")
 * @property-read BotAgentStatuses $botAgentStatuses Bot agent lifecycle status collection
 * @property-read GuardianAgentStatuses $guardianAgentStatuses Guardian run status collection
 */
final class RtChatContext extends RtContext
{
    public const string connections = 'connections';
    public const string userStates = 'userStates';
    public const string chatContexts = 'chatContexts';
    public const string botAgentStatuses = 'botAgentStatuses';
    public const string guardianAgentStatuses = 'guardianAgentStatuses';

    public const string connection = 'connection';
    public const string chatUserState = 'chatUserState';
    public const string chatContext = 'chatContext';
    public const string botAgentStatus = 'botAgentStatus';
    public const string guardianAgentStatus = 'guardianAgentStatus';

    /**
     * @throws StateCollectionNotFoundException
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = StateConnections::init();
        $this->_stateCollections[self::userStates] = StateUserStates::init();
        $this->_stateCollections[self::chatContexts] = StateChatContexts::init();
        $this->_stateCollections[self::botAgentStatuses] = StateBotAgentStatuses::init();
        $this->_stateCollections[self::guardianAgentStatuses] = StateGuardianAgentStatuses::init();
        $this->setRepresent(self::connections, Connections::class, ConnectionsActions::class, ConnectionActions::class);
        $this->setRepresent(self::userStates, UserStates::class, UserStatesActions::class);
        $this->setRepresent(self::chatContexts, ChatContexts::class, ChatContextsActions::class);
        $this->setRepresent(self::botAgentStatuses, BotAgentStatuses::class, BotAgentStatusesActions::class);
        $this->setRepresent(self::guardianAgentStatuses, GuardianAgentStatuses::class, GuardianAgentStatusesActions::class);
    }
}

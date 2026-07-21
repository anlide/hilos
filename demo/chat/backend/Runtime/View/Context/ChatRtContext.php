<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Context;

use Demo\Chat\Runtime\State\Collection\AttachmentDrafts as StateAttachmentDrafts;
use Demo\Chat\Runtime\State\Collection\BotAgentStatuses as StateBotAgentStatuses;
use Demo\Chat\Runtime\State\Collection\Connections as StateConnections;
use Demo\Chat\Runtime\State\Collection\GuardianAgentStatuses as StateGuardianAgentStatuses;
use Demo\Chat\Runtime\State\Collection\UserStates as StateUserStates;
use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Actions\Collection\AttachmentDraftsActions;
use Demo\Chat\Runtime\View\Actions\Collection\BotAgentStatusesActions;
use Demo\Chat\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\Chat\Runtime\View\Actions\Collection\GuardianAgentStatusesActions;
use Demo\Chat\Runtime\View\Actions\Collection\UserStatesActions;
use Demo\Chat\Runtime\View\Actions\Item\AttachmentDraftActions;
use Demo\Chat\Runtime\View\Actions\Item\BotAgentStatusActions;
use Demo\Chat\Runtime\View\Actions\Item\ChatContextActions;
use Demo\Chat\Runtime\View\Actions\Item\ChatUserStateActions;
use Demo\Chat\Runtime\View\Actions\Item\ConnectionActions;
use Demo\Chat\Runtime\View\Actions\Item\GuardianAgentStatusActions;
use Demo\Chat\Runtime\View\Collection\AttachmentDrafts;
use Demo\Chat\Runtime\View\Collection\BotAgentStatuses;
use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Collection\GuardianAgentStatuses;
use Demo\Chat\Runtime\View\Collection\UserStates;
use Demo\Chat\Runtime\View\Item\ChatContext;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateItemNotFoundException;
use Hilos\Runtime\State\Collection\BackupHistories as StateBackupHistories;
use Hilos\Runtime\State\Collection\OAuthPendingLogins as StateOAuthPendingLogins;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\OAuthPendingLogin as StateOAuthPendingLogin;
use Hilos\Runtime\View\Context\RtContext;

/**
 * ChatRtContext - chat runtime context (runtime data access for the chat app).
 *
 * Available collections:
 *   - connections: Active WebSocket connections (acceptKey → userId mapping)
 *   - userStates: Per-user outbound moderation and submit rate-limit state
 *   - attachmentDrafts: Uploaded quarantine files waiting for message submit
 *   - botAgentStatuses: Runtime lifecycle markers for bot agents
 *   - guardianAgentStatuses: Runtime UI statuses for Hilos guardian agents
 *
 * Usage:
 *   Hilos::$rt->connections[$acceptKey];
 *   Hilos::$rt->connections->actions->register($acceptKey, $userId);
 *   Hilos::$rt->connections[$acceptKey]->actions->… (per-connection writes and deletes)
 *   Hilos::$rt->userStates[$userId]; // key is string user id; offsetGet casts int to string
 *   Hilos::$rt->connections[$acceptKey]->attachmentDrafts;
 *   Hilos::$rt->chatContext;
 *
 * @property-read Connections $connections Active connections collection
 * @property-read ?Connection $selfConnection Current inbound WebSocket connection, or null outside a WS context
 * @property-read ChatContext $chatContext Shared chat topic and summary singleton
 * @property-read UserStates $userStates Per-user chat runtime state
 * @property-read AttachmentDrafts $attachmentDrafts Uploaded attachment drafts
 * @property-read BotAgentStatuses $botAgentStatuses Bot agent lifecycle status collection
 * @property-read GuardianAgentStatuses $guardianAgentStatuses Guardian run status collection
 */
final class ChatRtContext extends RtContext
{
    public const string connections = 'connections';
    public const string userStates = 'userStates';
    public const string attachmentDrafts = 'attachmentDrafts';
    public const string botAgentStatuses = 'botAgentStatuses';
    public const string guardianAgentStatuses = 'guardianAgentStatuses';

    public const string connection = 'connection';
    public const string chatUserState = 'chatUserState';
    public const string attachmentDraft = 'attachmentDraft';
    public const string chatContext = 'chatContext';
    public const string botAgentStatus = 'botAgentStatus';
    public const string guardianAgentStatus = 'guardianAgentStatus';
    public const string selfConnection = 'selfConnection';

    /**
     * Registers chat runtime state collections, singleton items, and view representations.
     *
     * @throws StateCollectionNotFoundException When a represented collection key is not registered
     * @throws StateItemNotFoundException When a represented singleton item key is not registered
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = StateConnections::init();
        $this->_stateCollections[self::userStates] = StateUserStates::init();
        $this->_stateCollections[self::attachmentDrafts] = StateAttachmentDrafts::init();
        $this->_stateItems[self::chatContext] = StateChatContext::create();
        $this->_stateCollections[self::botAgentStatuses] = StateBotAgentStatuses::init();
        $this->_stateCollections[self::guardianAgentStatuses] = StateGuardianAgentStatuses::init();
        // Framework-owned backup index; the browser view/representation lands in HIL-278.
        $this->_stateCollections[StateBackupHistory::RT_COLLECTION] = StateBackupHistories::init();
        $this->_stateItems[StateBackupRuntime::RT_ITEM] = StateBackupRuntime::create();
        // Framework-owned in-flight OAuth login ops: the callback handler writes them,
        // the OAuth agent observes and drains them. Internal — no browser view (HIL-281).
        $this->_stateCollections[StateOAuthPendingLogin::RT_COLLECTION] = StateOAuthPendingLogins::init();
        $this->_stateItems[self::selfConnection] = function (): ?StateConnection {
            /** @var StateConnections $connections */
            $connections = $this->_stateCollections[self::connections];

            return $connections->get(ExecutionContext::currentAcceptKey());
        };

        $this->setRepresent(
            self::connections,
            Connections::class,
            ConnectionsActions::class,
            ConnectionActions::class,
        );
        $this->setRepresent(
            self::userStates,
            UserStates::class,
            UserStatesActions::class,
            ChatUserStateActions::class,
        );
        $this->setRepresent(
            self::attachmentDrafts,
            AttachmentDrafts::class,
            AttachmentDraftsActions::class,
            AttachmentDraftActions::class,
        );
        $this->setRepresent(
            self::botAgentStatuses,
            BotAgentStatuses::class,
            BotAgentStatusesActions::class,
            BotAgentStatusActions::class,
        );
        $this->setRepresent(
            self::guardianAgentStatuses,
            GuardianAgentStatuses::class,
            GuardianAgentStatusesActions::class,
            GuardianAgentStatusActions::class,
        );
        $this->setRepresentItem(
            self::selfConnection,
            Connection::class,
            ConnectionActions::class,
        );
        $this->setRepresentItem(
            self::chatContext,
            ChatContext::class,
            ChatContextActions::class,
        );
    }
}

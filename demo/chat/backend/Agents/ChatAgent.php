<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\ModerationBotResultSignalData;
use Demo\Chat\Core\Router\DTO\UserPresenceEmitPayload;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Pages\ChatPageCatalog;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\DTO\EmitRtChangeSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;

/**
 * Monopolistic chat worker: database entities (users, events, bots, settings), runtime connections and user state,
 * WebSocket handshake/close, truth source lifecycle, and bot-specific agent signals.
 *
 * Registers as truth source for {@see DbChatContext} tables and {@see RtChatContext} collections on {@see self::onStart()}.
 */
class ChatAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::CHAT;

    /**
     * Register truth sources, seed runtime user states from DB, append {@see ChatEventType::CHAT_STARTED} to history.
     *
     * @throws HilosException On database failure or truth source registration failure
     */
    public function onStart(): void
    {
        // Register this agent as truth source for database tables (all keys)
        $this->registerDbTruthSource(DbChatContext::events);
        $this->registerDbTruthSource(DbChatContext::users);
        $this->registerDbTruthSource(DbChatContext::bots);
        $this->registerDbTruthSource(DbChatContext::moderatorPromptPieces);
        $this->registerDbTruthSource(DbChatContext::settings);

        // Register this agent as truth source for runtime collections (all keys)
        $this->registerRtTruthSource(RtChatContext::connections);
        $this->registerRtTruthSource(RtChatContext::userStates);

        Hilos::$rt->userStates->actions->seedAllFromDb();

        // Add chat started event to history (system event with userId = null)
        Hilos::$db->events->actions->addChatStarted();
    }

    /**
     * Authenticate session token, register the connection, emit user registration and presence updates, and send
     * {@see ChatSignalConstants::HANDSHAKE_RESPONSE} with the current user frontend projection and page catalog.
     *
     * Runtime presence is emitted after every successful connection register so
     * pages that show online session counts update for additional tabs, not only
     * first online transitions. Moderation and file-upload session state are sent
     * on main page subscribe only.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and query params (expects {@see HttpHeaders::SESSION_TOKEN})
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException On database, runtime, or truth source failure
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        $sessionToken = $data->queryParams[HttpHeaders::SESSION_TOKEN] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            $this->logAgentError(HttpHeaders::SESSION_TOKEN . " is required but not provided or empty");
            return;
        }

        $user = Hilos::$db->users->findBySession($sessionToken);
        $wasRegisteredNow = false;
        if ($user === null) {
            $user = Hilos::$db->users->actions->register($sessionToken);
            $wasRegisteredNow = true;
        }

        Hilos::$ac?->setBrowserSessionIdentity($sessionToken, 'user_id', (string)$user->id);

        Hilos::$rt->connections->actions->register($data->acceptKey, $user->id);

        $userFrontend = UserFrontendStateProjector::fullForUser($user);
        $newEvents = Events::initEmpty();
        if ($wasRegisteredNow) {
            $newEvents->add(Hilos::$db->events->actions->addUserRegistered($user->id));
        }

        if (count($newEvents) > 0) {
            $this->sendToAllUsers(
                ChatSignalConstants::NEW_EVENT,
                new ChatEventSignalDTO(
                    new EntitiesChangesDTO(full: [DbChatContext::events => $newEvents]),
                    frontend: UserFrontendStateProjector::updatesForUser($user, includePublicUser: true),
                ),
                $data->acceptKey,
            );
        }

        $this->broadcastUserPresence($user->id, $data->acceptKey);

        Hilos::$rt->userStates->actions->ensure($user->id);

        $this->sendToUser(
            ChatSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            new HandshakeResponseSignalData(
                frontend: $userFrontend,
                pageCatalog: ChatPageCatalog::getCatalog(),
            ),
        );
    }

    /**
     * Unregister the WebSocket connection and emit the new runtime presence summary.
     *
     * The summary is emitted after every close so online session counters update
     * when a user still has other active tabs.
     *
     * @param WebSocketCloseSignalDTO $data Closed connection {@see WebSocketCloseSignalDTO::$acceptKey}
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When runtime unregister fails
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        if (!isset(Hilos::$rt->connections[$data->acceptKey])) {
            return;
        }

        $userId = Hilos::$rt->connections[$data->acceptKey]->userId;
        Hilos::$rt->connections->actions->unregister($data->acceptKey);
        $this->broadcastUserPresence($userId);
    }

    /**
     * Queue a runtime presence emit for daemon-side page fan-out.
     *
     * The worker owns the runtime collection mutation, while the daemon-side
     * mapper owns current page subscription lookup and targeted WebSocket
     * delivery.
     *
     * @param int $userId User whose active connection summary changed
     * @param ?string $excludeAcceptKey Optional connection to skip during fan-out
     */
    private function broadcastUserPresence(int $userId, ?string $excludeAcceptKey = null): void
    {
        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            return;
        }

        $this->emitChangeRt(
            ChatSignalConstants::EMIT_CHAT_USER_PRESENCE_UPDATED,
            new EmitRtChangeSignalData(
                collectionKey: RtChatContext::connections,
                stateId: (string) $userId,
                payload: UserPresenceEmitPayload::fromFrontendChanges(
                    $userId,
                    UserFrontendStateProjector::updatesForUser($user),
                    UserFrontendStateProjector::updatesForUser($user, includeConnectionStats: true),
                )->toArray(),
                excludeAcceptKey: $excludeAcceptKey,
            ),
        );
    }

    /**
     * Append {@see ChatEventType::CHAT_STOPPED} and clear transient chat runtime state.
     *
     * @throws HilosException On database or runtime cleanup failure
     */
    public function onStop(): void
    {
        // Add chat stopped event to history (system event with userId = null)
        Hilos::$db->events->actions->addChatStopped();

        // Clear socket and per-user transient state before the worker unregisters truth sources.
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
    }

    /**
     * Run scheduled chat cleanup: delete chat events and broadcast the cleared event.
     *
     * File storage cleanup for the same cron is routed to MainPage.
     *
     * @param SignalDataInterface $data Cron payload (unused)
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Task name
     * @throws HilosException On database or truth source failure
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        if ($name !== ChatCronConstants::CLEANUP_HISTORY) {
            return;
        }

        Hilos::$db->events->actions->deleteAll();
        $event = Hilos::$db->events->actions->addChatCleared();

        $this->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(
                    full: [DbChatContext::events => Events::fromSingleItem($event)],
                    replaceFullKeys: [DbChatContext::events],
                ),
            ),
        );
    }

    /**
     * Dispatch chat-owned inter-agent signals and deliberately ignore page-owned moderation results.
     *
     * @param AgentSignalData $data Wrapped inner payload in {@see AgentSignalData::$data}
     * @param string $source Framework signal source identifier (unused)
     * @param string $name One of {@see ChatSignalConstants} agent signal names
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        $payload = $data->data;

        switch ($name) {
            case ChatSignalConstants::MODERATION_RESULT:
            case ChatSignalConstants::MODERATION_FILE_RESULT:
                return;
            case ChatSignalConstants::MODERATION_BOT_RESULT:
                if ($payload instanceof ModerationBotResultSignalData) {
                    $this->handleModerationBotResult($payload);
                } else {
                    $this->logAgentError("Invalid payload type for {$name}: " . get_debug_type($payload));
                }
                return;
            case ChatSignalConstants::BOT_JOINED:
            case ChatSignalConstants::BOT_LEFT:
                $this->sendToAllUsers($name, $data->data);
                return;
            default:
                $this->logAgentError("Invalid payload type for {$name}: " . get_debug_type($payload));
        }
    }

    /**
     * Apply bot message moderation: on allow, append {@see ChatEventType::MESSAGE_SENT} with `botId` and broadcast.
     *
     * @param ModerationBotResultSignalData $result Bot id, allow flag, message body, reason
     */
    private function handleModerationBotResult(ModerationBotResultSignalData $result): void
    {
        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $this->logAgentError("Bot message blocked by moderation (botId={$result->botId}; reason={$reason})");
            return;
        }

        $event = Hilos::$db->events->actions->addMessage($result->message, botId: $result->botId);
        $this->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(full: [DbChatContext::events => Events::fromSingleItem($event)])),
        );
    }
}

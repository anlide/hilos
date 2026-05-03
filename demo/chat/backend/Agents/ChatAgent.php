<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Agents\Exception\BotMessageModerationRejectedException;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Core\Router\DTO\ModerationBotResultSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Pages\ChatPageCatalog;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\Exception\MissingRequestQueryParamException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;

/**
 * Monopolistic chat worker for chat events, users, runtime connections, WebSocket lifecycle, and bot-specific signals.
 *
 * On start, registers chat database tables and runtime collections as truth sources.
 */
class ChatAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::CHAT;

    private const string SESSION_TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

    /**
     * Registers chat truth sources and records chat startup.
     *
     * @throws HilosException On database or runtime startup failure
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(DbChatContext::events);
        $this->registerDbTruthSource(DbChatContext::eventAttachments);
        $this->registerDbTruthSource(DbChatContext::users);
        $this->registerRtTruthSource(RtChatContext::connections);
        $this->registerRtTruthSource(RtChatContext::userStates);
        $this->registerRtTruthSource(RtChatContext::attachmentDrafts);

        Hilos::$db->events->actions->addChatStarted();
    }

    /**
     * Authenticates the session token, registers the connection, emits registration and presence updates, and sends
     * the handshake response with the current user frontend projection and page catalog.
     *
     * Runtime presence is emitted after every successful connection register so
     * pages that show online session counts update for additional tabs, not only
     * first online transitions. Outbound moderation, draft, and file-upload session
     * state are sent on main page subscribe only.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and query params with a required session token
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws MissingRequestQueryParamException When session token is missing
     * @throws EmptyValueException When session token is empty
     * @throws InvalidFormatException When session token is not a 32-character lowercase hex string
     * @throws HilosException On database or runtime failure
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        $sessionToken = $data->queryParams->requireStringMatching(
            HttpHeaders::SESSION_TOKEN,
            self::SESSION_TOKEN_PATTERN,
            HttpHeaders::SESSION_TOKEN . ' must be a 32-character lowercase hex token',
        );

        $user = Hilos::$db->users->findBySession($sessionToken);
        $wasRegisteredNow = false;
        if ($user === null) {
            $user = Hilos::$db->users->actions->register($sessionToken);
            $wasRegisteredNow = true;
        }
        $userId = (int)$user->id;

        Hilos::$ac?->setBrowserSessionIdentity($sessionToken, 'user_id', (string)$userId);

        Hilos::$rt->connections->actions->register($data->acceptKey, $userId);
        Hilos::$rt->userStates->actions->ensure($userId);

        if ($wasRegisteredNow) {
            Hilos::$db->events->actions->addUserRegistered($userId);
        }

        $this->sendToUser(
            ChatSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            new HandshakeResponseSignalData(
                frontend: UserFrontendStateProjector::fullForUser($user),
                pageCatalog: ChatPageCatalog::getCatalog(),
            ),
        );
    }

    /**
     * Delete connection-owned attachment drafts and unregister the WebSocket connection.
     *
     * The summary is emitted after every close so online session counters update
     * when a user still has other active tabs.
     *
     * @param WebSocketCloseSignalDTO $data Closed WebSocket connection
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException When runtime unregister fails
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        Hilos::$rt->attachmentDrafts->actions->deleteForAcceptKey($data->acceptKey, deleteFiles: true);
        Hilos::$rt->connections[$data->acceptKey]?->actions->unregister();
    }

    /**
     * Records chat shutdown and clears transient chat runtime state.
     *
     * @throws HilosException On database or runtime cleanup failure
     */
    public function onStop(): void
    {
        Hilos::$db->events->actions->addChatStopped();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: true);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
    }

    /**
     * Handles chat-owned cron names by replacing the event stream with a cleared event.
     *
     * Cron routing is shared with MainPage; page-only cron names are deliberately ignored here.
     *
     * @param SignalDataInterface $data Cron payload (unused)
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Task name
     * @throws HilosException On history cleanup failure
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatCronConstants::CLEANUP_HISTORY:
                Hilos::$db->events->actions->deleteAll();
                Hilos::$db->events->actions->addChatCleared();

                return;
        }
    }

    /**
     * Dispatches chat-owned inter-agent signals and deliberately ignores page-owned moderation results.
     *
     * @param AgentSignalData $data Agent signal wrapper with the inner payload to dispatch
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this agent
     * @throws InvalidAgentSignalPayloadException When signal payload does not match the signal name
     * @throws HilosException On bot message publish failure
     * @throws CommandException If event id is null after sync
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatSignalConstants::MODERATION_RESULT:
                return;
            case ChatSignalConstants::MODERATION_BOT_RESULT:
                if (!$data->data instanceof ModerationBotResultSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, ModerationBotResultSignalData::class, $data->data);
                }
                $this->handleModerationBotResult($data->data);
                return;
            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Publishes allowed moderated bot messages and rejects blocked bot messages.
     *
     * @param ModerationBotResultSignalData $result Bot id, allow flag, message body, reason
     * @throws BotMessageModerationRejectedException When moderation rejects the bot message
     * @throws HilosException On bot message persistence failure
     * @throws CommandException If event id is null after sync
     */
    private function handleModerationBotResult(ModerationBotResultSignalData $result): void
    {
        if (!$result->allow) {
            throw new BotMessageModerationRejectedException($result->botId, $result->reason !== '' ? $result->reason : 'unknown');
        }

        Hilos::$db->events->actions->addMessage($result->message, botId: $result->botId);
    }
}

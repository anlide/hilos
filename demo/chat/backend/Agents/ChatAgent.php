<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Pages\ChatPageCatalog;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
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
 * Monopolistic chat worker for chat events, users, runtime connections, WebSocket lifecycle, and bot messages.
 *
 * On start, registers chat database tables and runtime collections as truth sources.
 */
final class ChatAgent extends AbstractAgent
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
        $this->registerDbTruthSource(ChatDbContext::events);
        $this->registerDbTruthSource(ChatDbContext::eventMessages);
        $this->registerDbTruthSource(ChatDbContext::eventUserRegistrations);
        $this->registerDbTruthSource(ChatDbContext::eventUserRenames);
        $this->registerDbTruthSource(ChatDbContext::eventAttachments);
        $this->registerDbTruthSource(ChatDbContext::users);
        $this->registerRtTruthSource(ChatRtContext::connections);
        $this->registerRtTruthSource(ChatRtContext::userStates);
        $this->registerRtTruthSource(ChatRtContext::attachmentDrafts);

        Hilos::$db->events->actions->addChatStarted();
    }

    /**
     * Authenticates the session token, registers the connection, emits registration updates, and sends
     * the handshake response with the current user frontend state and page catalog.
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

        Hilos::$ac?->identifyBrowserSessionUser($sessionToken, $userId);

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
     * @throws HilosException On runtime cleanup failure
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        Hilos::$rt->selfConnection?->attachmentDrafts->actions->deleteAllWithFiles();
        Hilos::$rt->selfConnection?->actions->unregister();
    }

    /**
     * Records chat shutdown and clears transient chat runtime state.
     *
     * @throws HilosException On database or runtime cleanup failure
     */
    public function onStop(): void
    {
        Hilos::$db->events->actions->addChatStopped();
        Hilos::$rt->attachmentDrafts->actions->clearWithFiles();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
    }

    /**
     * Handles chat-owned cron cleanup for persisted history and transient attachment state.
     *
     * @param SignalDataInterface $data Cron payload (unused)
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Task name
     * @throws AgentUnknownSignalException When cron name is not supported
     * @throws HilosException On history, runtime, or filesystem cleanup failure
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatCronConstants::CLEANUP_HISTORY:
                Hilos::$db->events->actions->deleteAll();
                Hilos::$db->events->actions->addChatCleared();
                $this->deleteAllAttachmentFilesFromDisk();

                return;

            case ChatCronConstants::CLEANUP_ATTACHMENT_DRAFTS:
                Hilos::$rt->attachmentDrafts->actions->deleteExpired();

                return;

            default:
                throw new AgentUnknownSignalException($name);
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
            case ChatSignalConstants::RENAME_MODERATION_RESULT:
                return;
            case ChatSignalConstants::BOT_MESSAGE:
                if (!$data->data instanceof BotMessageSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        BotMessageSignalData::class,
                        $data->data,
                    );
                }
                $this->handleBotMessage($data->data);
                return;
            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Publishes a generated bot message to the chat event stream.
     *
     * @param BotMessageSignalData $message Bot id and generated message body
     * @throws HilosException On bot message persistence failure
     * @throws CommandException If event id is null after sync
     */
    private function handleBotMessage(BotMessageSignalData $message): void
    {
        Hilos::$db->events->actions->addMessage($message->message, botId: $message->botId);
    }

    /**
     * Deletes all attachment files on disk and resets file-related runtime fields.
     *
     * @throws HilosException On runtime or filesystem cleanup failure
     */
    private function deleteAllAttachmentFilesFromDisk(): void
    {
        Hilos::$fs->published->deleteAll();
        Hilos::$fs->quarantine->deleteAll();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$rt->connections->actions->clearAllFileRuntimeOnAllConnections();
    }
}

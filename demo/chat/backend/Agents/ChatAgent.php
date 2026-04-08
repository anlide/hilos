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
use Demo\Chat\Core\Router\DTO\ModerationFileResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Pages\ChatPageCatalog;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Database\View\Collection\Users;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Core\Router\DTO\ModerationStateUpdateSignalData;
use Demo\Chat\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;

/**
 * ChatAgent - Monopolistic agent for chat management.
 *
 * Runs in monopolistic worker process. Manages chat state and history.
 * State stored only in memory (no persistence).
 */
class ChatAgent extends AbstractAgent
{
    use ChatAgentFileAttachments;

    public const string AGENT_TYPE = AgentType::CHAT;

    /** @var int Message rate limit in seconds per user */
    private const int MESSAGE_RATE_LIMIT_SECONDS = 10;

    /** @var array<int, float> userId => last message timestamp (microtime) */
    private array $lastMessageTimestampByUser = [];

    /**
     * Called when agent is started.
     *
     * @throws HilosException If database operation fails or truth source registration fails
     */
    public function onStart(): void
    {
        // Register this agent as truth source for database tables (all keys)
        TruthSourceRegistry::register(DbChatContext::events, true, $this->getId());
        TruthSourceRegistry::register(DbChatContext::users, true, $this->getId());
        TruthSourceRegistry::register(DbChatContext::bots, true, $this->getId());
        TruthSourceRegistry::register(DbChatContext::moderatorPromptPieces, true, $this->getId());
        TruthSourceRegistry::register(DbChatContext::settings, true, $this->getId());

        // Register this agent as truth source for runtime collections (all keys)
        RtTruthSourceRegistry::register(RtChatContext::connections, true, $this->getId());
        RtTruthSourceRegistry::register(RtChatContext::moderationStates, true, $this->getId());

        // Add chat started event to history (system event with userId = null)
        Hilos::$db->events->actions->add(ChatEventType::CHAT_STARTED->value);
    }

    /**
     * Handle handshake signal from WebSocket client.
     *
     * @param WebSocketHandshakeSignalDTO $data Handshake data (acceptKey, queryParams)
     * @param string $source Signal source identifier
     * @param string $name Signal name
     * @throws HilosException If database, runtime or truth source check fails
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        $sessionToken = $data->queryParams[HttpHeaders::SESSION_TOKEN] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            Logger::logAgentError($this->getId(), HttpHeaders::SESSION_TOKEN . " is required but not provided or empty");
            return;
        }

        $user = Hilos::$db->users->findBySession($sessionToken);
        $wasRegisteredNow = false;
        if ($user === null) {
            $user = Hilos::$db->users->actions->register($sessionToken);
            $wasRegisteredNow = true;
        }

        Hilos::$ac?->setBrowserSessionIdentity($sessionToken, 'user_id', (string)$user->id);

        $hadNoConnections = count(Hilos::$rt->connections->forUser($user->id)) === 0;
        Hilos::$rt->connections->actions->register($data->acceptKey, $user->id);

        $userEntities = new EntitiesChangesDTO(full: [DbChatContext::users => Users::fromSingleItem($user)]);
        $newEvents = Events::initEmpty();
        if ($wasRegisteredNow) {
            $newEvents->add(Hilos::$db->events->actions->add(ChatEventType::USER_REGISTERED->value, $user->id));
        }
        if ($hadNoConnections) {
            $newEvents->add(Hilos::$db->events->actions->add(ChatEventType::USER_ONLINE->value, $user->id));
        }

        if (count($newEvents) > 0) {
            $this->sendToAllUsers(
                ChatSignalConstants::NEW_EVENT,
                new ChatEventSignalDTO($userEntities->withFullAppended(DbChatContext::events, $newEvents)),
                $data->acceptKey,
            );
        }

        $moderationState = isset(Hilos::$rt->moderationStates[$user->id])
            ? Hilos::$rt->moderationStates[$user->id]->message
            : null;

        $fileMod = $this->getFileModerationUiPayloadForUser($user->id);
        $fileProgress = $this->getFileUploadProgressPayloadForUser($user->id);

        $this->sendToUser(
            ChatSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            new HandshakeResponseSignalData(
                entities: $userEntities,
                userId: $user->id,
                moderationState: $moderationState,
                fileModerationState: $fileMod,
                fileUploadProgress: $fileProgress,
                pageCatalog: ChatPageCatalog::getCatalog(),
            ),
        );
    }

    /**
     * Handle connection close signal (WebSocket connection closed).
     * Unregisters connection from runtime so that relevantUsers and state stay correct.
     *
     * @param WebSocketCloseSignalDTO $data Close signal data (acceptKey)
     * @param string $source Signal source identifier
     * @param string $name Signal name
     * @throws HilosException If runtime unregister fails
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        if (!isset(Hilos::$rt->connections[$data->acceptKey])) {
            return;
        }

        $userId = Hilos::$rt->connections[$data->acceptKey]->userId;
        Hilos::$rt->connections->actions->unregister($data->acceptKey);

        if (count(Hilos::$rt->connections->forUser($userId)) === 0) {
            $event = Hilos::$db->events->actions->add(ChatEventType::USER_OFFLINE->value, $userId);
            $user = Hilos::$db->users[$userId] ?? null;
            if ($user === null) {
                return;
            }

            $this->sendToAllUsers(
                ChatSignalConstants::NEW_EVENT,
                new ChatEventSignalDTO(
                    new EntitiesChangesDTO(
                        full: [
                            DbChatContext::users => Users::fromSingleItem($user),
                            DbChatContext::events => Events::fromSingleItem($event),
                        ],
                    ),
                ),
            );
        }
    }

    /**
     * Called when agent is stopped.
     *
     * @throws HilosException If database operation or unregistration fails
     */
    public function onStop(): void
    {
        // Add chat stopped event to history (system event with userId = null)
        Hilos::$db->events->actions->add(ChatEventType::CHAT_STOPPED->value);

        // Clear all connections before unregistering
        Hilos::$rt->connections->actions->clear();

        // Unregister from both database and runtime truth source registries
        TruthSourceRegistry::unregisterAgent($this->getId());
        RtTruthSourceRegistry::unregisterAgent($this->getId());
    }

    /**
     * Handle cron signal.
     *
     * @param SignalDataInterface $data Cron signal payload
     * @param string $source Signal source identifier
     * @param string $name Signal name (e.g. CLEANUP_HISTORY)
     * @throws HilosException If database or truth source check fails
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        // Handle cleanup cron task
        if ($name === ChatCronConstants::CLEANUP_HISTORY) {
            $this->deleteAllAttachmentFilesFromDisk();
            Hilos::$db->events->actions->deleteAll();

            $event = Hilos::$db->events->actions->add(ChatEventType::CHAT_CLEARED->value);

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
    }

    /**
     * Handle agent-to-agent signals (moderation result from ModeratorAgent).
     *
     * @param AgentSignalData $data Agent signal payload (e.g. ModerationResultSignalData)
     * @param string $source Source agent identifier
     * @param string $name Signal name (e.g. MODERATION_RESULT)
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        $payload = $data->data;

        switch ($name) {
            case ChatSignalConstants::MODERATION_RESULT:
                if ($payload instanceof ModerationResultSignalData) {
                    $this->handleModerationResult($payload);
                } else {
                    $this->logInvalidAgentPayload($name, $payload);
                }
                return;
            case ChatSignalConstants::MODERATION_BOT_RESULT:
                if ($payload instanceof ModerationBotResultSignalData) {
                    $this->handleModerationBotResult($payload);
                } else {
                    $this->logInvalidAgentPayload($name, $payload);
                }
                return;
            case ChatSignalConstants::MODERATION_FILE_RESULT:
                if ($payload instanceof ModerationFileResultSignalData) {
                    $this->handleModerationFileResult($payload);
                } else {
                    $this->logInvalidAgentPayload($name, $payload);
                }
                return;
            case ChatSignalConstants::BOT_JOINED:
            case ChatSignalConstants::BOT_LEFT:
                $this->sendToAllUsers($name, $data->data);
                return;
            default:
                $this->logInvalidAgentPayload($name, $payload);
        }
    }

    /**
     * Log invalid agent payload type for signal.
     *
     * @param string $name Signal name
     * @param mixed $payload Actual payload (logged for debugging)
     */
    private function logInvalidAgentPayload(string $name, mixed $payload): void
    {
        Logger::logAgentError($this->getId(), "Invalid payload type for {$name}: " . get_class($payload));
    }

    /**
     * Process moderation result: clear state, notify user, publish message if allowed.
     *
     * @param ModerationResultSignalData $result Moderation result from ModeratorAgent
     */
    private function handleModerationResult(ModerationResultSignalData $result): void
    {
        $acceptKey = $result->acceptKey;
        $userId = $result->userId;

        Hilos::$rt->moderationStates->actions->clear($userId);
        $this->sendModerationStateToUserConnections($userId, null);

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            Logger::logAgentError($this->getId(), "Message blocked by moderation (userId={$userId}; reason={$reason})");
            return;
        }

        $this->recordMessageSent($userId);
        $event = Hilos::$db->events->actions->add(ChatEventType::MESSAGE_SENT->value, $userId, null, ['message' => $result->message]);
        $this->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(full: [DbChatContext::events => Events::fromSingleItem($event)])),
        );
    }

    /**
     * Process bot message moderation result: publish message if allowed.
     *
     * @param ModerationBotResultSignalData $result Moderation result for bot message
     */
    private function handleModerationBotResult(ModerationBotResultSignalData $result): void
    {
        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            Logger::logAgentError($this->getId(), "Bot message blocked by moderation (botId={$result->botId}; reason={$reason})");
            return;
        }

        $event = Hilos::$db->events->actions->add(ChatEventType::MESSAGE_SENT->value, null, $result->botId, ['message' => $result->message]);
        $this->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(full: [DbChatContext::events => Events::fromSingleItem($event)])),
        );
    }

    /**
     * Sends moderation state update to all connections of a user.
     * Private data - only the user's own connections receive this.
     *
     * @param int $userId User ID
     * @param ?string $moderationState Current moderation state (message text or null when cleared)
     */
    public function sendModerationStateToUserConnections(int $userId, ?string $moderationState): void
    {
        $connections = Hilos::$rt->connections->forUser($userId);
        $data = new ModerationStateUpdateSignalData(moderationState: $moderationState);
        foreach ($connections as $connection) {
            $this->sendToUser(ChatSignalConstants::MODERATION_STATE_UPDATE, $connection->acceptKey, $data);
        }
    }

    /**
     * Check whether user is allowed to send a message under rate limit.
     *
     * Limit: one message per {@see MESSAGE_RATE_LIMIT_SECONDS} seconds per user.
     * Applied as (limit - 1) seconds to avoid blocking legitimate messages from frontend timer drift.
     *
     * @param int $userId User ID
     * @return bool True if user can send, false if rate limited
     */
    public function canSendMessage(int $userId): bool
    {
        $now = microtime(true);
        $last = $this->lastMessageTimestampByUser[$userId] ?? 0.0;
        $effectiveLimit = self::MESSAGE_RATE_LIMIT_SECONDS - 1;
        return ($now - $last) >= $effectiveLimit;
    }

    /**
     * Record that user has successfully sent a message (updates rate limit timestamp).
     *
     * Call after message is stored and broadcast to all users.
     *
     * @param int $userId User ID
     */
    public function recordMessageSent(int $userId): void
    {
        $this->lastMessageTimestampByUser[$userId] = microtime(true);
    }
}

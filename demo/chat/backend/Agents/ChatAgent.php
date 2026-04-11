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
 * Monopolistic chat worker: database entities (users, events, bots, settings), runtime connections and user state,
 * WebSocket handshake/close, text and bot moderation routing, message rate limiting, and file attachments
 * ({@see ChatAgentFileAttachments}).
 *
 * Registers as truth source for {@see DbChatContext} tables and {@see RtChatContext} collections on {@see self::onStart()}.
 */
class ChatAgent extends AbstractAgent
{
    use ChatAgentFileAttachments;

    public const string AGENT_TYPE = AgentType::CHAT;

    /**
     * Minimum interval in seconds between chat messages from the same user (see {@see self::canSendMessage()}).
     */
    private const int MESSAGE_RATE_LIMIT_SECONDS = 10;

    /**
     * Last successful text message send time per user (`microtime(true)`), for rate limiting.
     *
     * @var array<int, float>
     */
    private array $lastMessageTimestampByUser = [];

    /**
     * Register truth sources, seed runtime user states from DB, append {@see ChatEventType::CHAT_STARTED} to history.
     *
     * @throws HilosException On database failure or truth source registration failure
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
        RtTruthSourceRegistry::register(RtChatContext::userStates, true, $this->getId());

        Hilos::$rt->userStates->actions->seedAllFromDb();

        // Add chat started event to history (system event with userId = null)
        Hilos::$db->events->actions->add(ChatEventType::CHAT_STARTED->value);
    }

    /**
     * Authenticate session token, register the connection, optionally emit user registration/online events, and send
     * {@see ChatSignalConstants::HANDSHAKE_RESPONSE} with entities, moderation text state, file moderation UI, and
     * in-flight upload progress when present.
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

        Hilos::$rt->userStates->actions->ensure($user->id);
        $session = $this->buildUserSessionSnapshotForAcceptKey($data->acceptKey);

        $this->sendToUser(
            ChatSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            new HandshakeResponseSignalData(
                entities: $userEntities,
                userId: $user->id,
                moderationState: $session['moderationState'],
                fileModerationState: $session['fileModerationState'],
                fileUploadProgress: $session['fileUploadProgress'],
                pageCatalog: ChatPageCatalog::getCatalog(),
            ),
        );
    }

    /**
     * Unregister the WebSocket connection; if that was the user's last tab, append {@see ChatEventType::USER_OFFLINE} and broadcast.
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
     * Append {@see ChatEventType::CHAT_STOPPED}, clear all runtime connections, unregister truth sources.
     *
     * @throws HilosException On database failure or truth source unregistration failure
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
     * Run scheduled tasks: for {@see ChatCronConstants::CLEANUP_HISTORY}, wipe attachments, delete all events,
     * broadcast {@see ChatEventType::CHAT_CLEARED}.
     *
     * @param SignalDataInterface $data Cron payload (type-specific; may be unused)
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Task name (e.g. {@see ChatCronConstants::CLEANUP_HISTORY})
     * @throws HilosException On database or truth source failure
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
     * Dispatch inter-agent signals: text/bot/file moderation results, and bot presence fan-out to all users.
     *
     * @param AgentSignalData $data Wrapped inner payload in {@see AgentSignalData::$data}
     * @param string $source Framework signal source identifier (unused)
     * @param string $name One of {@see ChatSignalConstants} moderation/bot agent signal names
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
     * Log a type mismatch for an incoming agent signal (expected DTO not received).
     *
     * @param string $name Signal name that failed validation
     * @param mixed $payload Value received in {@see AgentSignalData::$data}
     */
    private function logInvalidAgentPayload(string $name, mixed $payload): void
    {
        Logger::logAgentError($this->getId(), "Invalid payload type for {$name}: " . get_class($payload));
    }

    /**
     * Apply text message moderation: clear pending moderation on all user tabs; if allowed, record rate limit and add {@see ChatEventType::MESSAGE_SENT}.
     *
     * @param ModerationResultSignalData $result Uploader connection key, user id, allow flag, message body, reason
     */
    private function handleModerationResult(ModerationResultSignalData $result): void
    {
        $acceptKey = $result->acceptKey;
        $userId = $result->userId;

        Hilos::$rt->userStates->actions->ensure($userId);
        Hilos::$rt->userStates->actions->clearTextModerationMessage($userId);
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
     * Apply bot message moderation: on allow, append {@see ChatEventType::MESSAGE_SENT} with `botId` and broadcast.
     *
     * @param ModerationBotResultSignalData $result Bot id, allow flag, message body, reason
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
     * Build text moderation, file moderation UI, and binary upload progress for one connection (handshake / main subscribe).
     *
     * @param string $acceptKey Connection accept key
     * @return array{moderationState: ?string, fileModerationState: ?array, fileUploadProgress: ?array<string, mixed>}
     */
    public function buildUserSessionSnapshotForAcceptKey(string $acceptKey): array
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return [
                'moderationState' => null,
                'fileModerationState' => null,
                'fileUploadProgress' => null,
            ];
        }

        $userId = Hilos::$rt->connections[$acceptKey]->userId;
        Hilos::$rt->userStates->actions->ensure($userId);
        $pending = Hilos::$rt->userStates[(string)$userId]->moderationMessage;
        $moderationState = $pending !== '' ? $pending : null;

        $fileMod = $this->getFileModerationUiPayloadForAcceptKey($acceptKey);

        $conn = Hilos::$rt->connections[$acceptKey];
        $fileProgress = null;
        if ($conn->fileProgressFilename !== null) {
            $fileProgress = [
                'filename' => $conn->fileProgressFilename,
                'uploadedBytes' => $conn->fileProgressUploadedBytes,
                'totalBytes' => $conn->fileProgressTotalBytes,
            ];
        }

        return [
            'moderationState' => $moderationState,
            'fileModerationState' => $fileMod,
            'fileUploadProgress' => $fileProgress,
        ];
    }

    /**
     * Push {@see ChatSignalConstants::MODERATION_STATE_UPDATE} to every WebSocket connection of `$userId` (pending message text or null).
     *
     * @param int $userId Chat user id
     * @param ?string $moderationState Pending moderated message text, or null to clear the banner
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
     * Whether enough time has passed since {@see self::recordMessageSent()} for this user to send another message.
     *
     * Uses {@see self::MESSAGE_RATE_LIMIT_SECONDS} minus one second effective window to reduce false blocks from client timer drift.
     *
     * @param int $userId Chat user id
     * @return bool True if sending is allowed, false if still within the limit window
     */
    public function canSendMessage(int $userId): bool
    {
        $now = microtime(true);
        $last = $this->lastMessageTimestampByUser[$userId] ?? 0.0;
        $effectiveLimit = self::MESSAGE_RATE_LIMIT_SECONDS - 1;
        return ($now - $last) >= $effectiveLimit;
    }

    /**
     * Store `microtime(true)` for `$userId` after a moderated message is persisted and broadcast.
     *
     * @param int $userId Chat user id
     */
    public function recordMessageSent(int $userId): void
    {
        $this->lastMessageTimestampByUser[$userId] = microtime(true);
    }
}

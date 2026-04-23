<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\FileModerationStateUpdateSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadCompleteSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadInvalidSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadProgressUpdateSignalData;
use Demo\Chat\Core\Router\DTO\ModerationBotResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationFileRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationFileResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Pages\ChatPageCatalog;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Database\View\Collection\Users;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Main\UploadFileTrait;
use Demo\Chat\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Demo\Chat\Core\Router\DTO\ModerationStateUpdateSignalData;
use Demo\Chat\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;

/**
 * Monopolistic chat worker: database entities (users, events, bots, settings), runtime connections and user state,
 * WebSocket handshake/close, text and bot moderation routing, message rate limiting, and file attachments
 * (binary WebSocket upload, quarantine, moderation result handling; main-page upload init lives in {@see UploadFileTrait}).
 *
 * Registers as truth source for {@see DbChatContext} tables and {@see RtChatContext} collections on {@see self::onStart()}.
 */
class ChatAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::CHAT;

    /**
     * Minimum interval in seconds between chat messages from the same user (see {@see self::canSendMessage()}).
     */
    private const int MESSAGE_RATE_LIMIT_SECONDS = 10;

    /**
     * Minimum wall-clock interval between {@see ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE} sends when not forced.
     */
    private const float FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC = 0.3;

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
        Hilos::$db->events->actions->addChatStarted();
    }

    /**
     * Authenticate session token, register the connection, optionally emit user registration/online events, and send
     * {@see ChatSignalConstants::HANDSHAKE_RESPONSE} with the current user in entities and page catalog.
     * Moderation and file-upload session state are sent on main page subscribe only.
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
            $newEvents->add(Hilos::$db->events->actions->addUserRegistered($user->id));
        }
        if ($hadNoConnections) {
            $newEvents->add(Hilos::$db->events->actions->addUserOnline($user->id));
        }

        if (count($newEvents) > 0) {
            $this->sendToAllUsers(
                ChatSignalConstants::NEW_EVENT,
                new ChatEventSignalDTO($userEntities->withFullAppended(DbChatContext::events, $newEvents)),
                $data->acceptKey,
            );
        }

        Hilos::$rt->userStates->actions->ensure($user->id);

        $this->sendToUser(
            ChatSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            new HandshakeResponseSignalData(
                entities: $userEntities,
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
            $event = Hilos::$db->events->actions->addUserOffline($userId);
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
        Hilos::$db->events->actions->addChatStopped();

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
     * Apply text message moderation: clear pending state for this connection; if allowed, record rate limit and add {@see ChatEventType::MESSAGE_SENT}.
     *
     * @param ModerationResultSignalData $result Uploader connection key, user id, allow flag, message body, reason
     */
    private function handleModerationResult(ModerationResultSignalData $result): void
    {
        $acceptKey = $result->acceptKey;
        $userId = $result->userId;

        Hilos::$rt->userStates->actions->clearTextModerationMessage($userId);
        $this->sendToUser(
            ChatSignalConstants::MODERATION_STATE_UPDATE,
            $acceptKey,
            new ModerationStateUpdateSignalData(moderationState: null),
        );

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            Logger::logAgentError($this->getId(), "Message blocked by moderation (userId={$userId}; reason={$reason})");
            return;
        }

        $this->recordMessageSent($userId);
        $event = Hilos::$db->events->actions->addMessage($result->message, userId: $userId);
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

        $event = Hilos::$db->events->actions->addMessage($result->message, botId: $result->botId);
        $this->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(full: [DbChatContext::events => Events::fromSingleItem($event)])),
        );
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

    /**
     * WebSocket binary frame handler: append chunk to the quarantine file, update received bytes, optionally emit
     * throttled {@see ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE}, then finalize when size matches declared total.
     *
     * Connection-scoped state is read and written only via {@see Hilos::$rt->connections}[`$acceptKey`] (no cached
     * {@see RuntimeConnection} items across mutations). Per-socket writes use {@see RuntimeConnection::$actions};
     * collection-level helpers remain on {@see ConnectionsActions} (register,
     * unregister, clear, bulk file reset). User-targeted signals use the owning connection `acceptKey` only.
     *
     * @param WebSocketFrameBinarySignalDTO $data Frame payload and {@see WebSocketFrameBinarySignalDTO::$acceptKey}
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws \Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException When the connections actions collection name is null
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $acceptKey = $data->acceptKey;
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            Logger::logAgentInfo($this->getId(), 'frame_binary: unknown acceptKey, ignoring');

            return;
        }
        if (Hilos::$rt->connections[$acceptKey]->fileSessionUploadId === null) {
            Logger::logAgentInfo(
                $this->getId(),
                'frame_binary: no upload session acceptKey=' . $acceptKey
                . ' userId=' . Hilos::$rt->connections[$acceptKey]->userId,
            );
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_INVALID,
                $acceptKey,
                new FileUploadInvalidSignalData('no_active_upload'),
            );

            return;
        }

        $len = strlen($data->payload);
        $declared = Hilos::$rt->connections[$acceptKey]->fileSessionDeclaredSize;
        $received = Hilos::$rt->connections[$acceptKey]->fileSessionReceivedBytes;
        if ($received + $len > $declared) {
            Logger::logAgentError(
                $this->getId(),
                'frame_binary: overflow acceptKey=' . $acceptKey
                . ' userId=' . Hilos::$rt->connections[$acceptKey]->userId,
            );
            $this->failFileUploadSession($acceptKey, 'size_overflow');

            return;
        }

        $tmpIndex = Hilos::$rt->connections[$acceptKey]->fileSessionQuarantineBasename;
        try {
            Hilos::$fs->tmp[$tmpIndex]->append($data->payload);
        } catch (FsException $e) {
            Logger::logAgentError(
                $this->getId(),
                'frame_binary: tmp append failed acceptKey=' . $acceptKey
                . ' userId=' . Hilos::$rt->connections[$acceptKey]->userId
                . ' error=' . $e->getMessage(),
            );
            $this->failFileUploadSession($acceptKey, 'write_error');

            return;
        }

        $newReceived = $received + $len;
        Hilos::$rt->connections[$acceptKey]->actions->applyStoredBinaryChunkProgress($newReceived);

        $this->sendFileUploadProgressUpdateThrottled($acceptKey, $newReceived === $declared);

        if ($newReceived === $declared) {
            $this->completeFileUpload($acceptKey);
        }
    }

    /**
     * Delete all attachment files on disk, reset file-related runtime fields on every connection, notify clients.
     *
     * Used from admin/cron cleanup ({@see self::onSignalCron}) so all tabs drop moderation and progress UI.
     */
    public function deleteAllAttachmentFilesFromDisk(): void
    {
        Hilos::$fs->published->deleteAll();
        Hilos::$fs->quarantine->deleteAll();
        Hilos::$rt->connections->actions->clearAllFileRuntimeOnAllConnections();

        foreach (Hilos::$rt->connections as $conn) {
            $ak = $conn->acceptKey;
            $this->sendToUser(
                ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                $ak,
                new FileModerationStateUpdateSignalData(null),
            );
            $this->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
                $ak,
                new FileUploadProgressUpdateSignalData(null),
            );
        }
    }

    /**
     * Apply {@see ChatSignalConstants::MODERATION_FILE_RESULT}: delete quarantine on reject, or move to published and append a {@see ChatEventType::FILE_SHARED} event.
     *
     * Updates moderation UI on the uploader's socket only while {@see ModerationFileResultSignalData::$acceptKey} is still
     * registered and {@see ModerationFileResultSignalData::$userId} matches that connection.
     *
     * @throws HilosException
     */
    public function handleModerationFileResult(ModerationFileResultSignalData $result): void
    {
        $storedName = $result->quarantineBasename;
        $quarantineFile = Hilos::$fs->quarantine[$storedName];
        $acceptKey = $result->acceptKey;
        $live = isset(Hilos::$rt->connections[$acceptKey])
            && Hilos::$rt->connections[$acceptKey]->userId === $result->userId;

        if (!$result->allow) {
            $quarantineFile->unlink();
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            Logger::logAgentError($this->getId(), "File blocked by moderation (userId={$result->userId}; reason={$reason})");
            if ($live) {
                $rejConn = Hilos::$rt->connections[$acceptKey];
                $rejConn->actions->markFileModerationRejected(
                    $result->originalFilename,
                    $result->size,
                    $reason,
                );
                $rejPhase = $rejConn->fileModPhase;
                $this->sendToUser(
                    ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                    $acceptKey,
                    new FileModerationStateUpdateSignalData(
                        $rejPhase === null ? null : [
                            'phase' => $rejPhase,
                            'filename' => $rejConn->fileModFilename,
                            'uploadedBytes' => $rejConn->fileModUploadedBytes,
                            'totalBytes' => $rejConn->fileModTotalBytes,
                            'reason' => $rejConn->fileModReason !== '' ? $rejConn->fileModReason : null,
                            'updatedAt' => $rejConn->fileModUpdatedAt,
                        ],
                    ),
                );
            }

            return;
        }

        if (!$quarantineFile->exists()) {
            Logger::logAgentError($this->getId(), "Moderation allow but quarantine file missing: {$storedName}");
            if ($live) {
                Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
                $this->sendToUser(
                    ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                    $acceptKey,
                    new FileModerationStateUpdateSignalData(null),
                );
            }

            return;
        }

        try {
            $quarantineFile->move('published');
        } catch (FsException $e) {
            Logger::logAgentError($this->getId(), "Failed to move file to published: {$e->getMessage()}");
            $quarantineFile->unlink();
            if ($live) {
                Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
                $this->sendToUser(
                    ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                    $acceptKey,
                    new FileModerationStateUpdateSignalData(null),
                );
            }

            return;
        }

        if ($live) {
            Hilos::$rt->connections[$acceptKey]->actions->clearFileModerationBanner();
            $this->sendToUser(
                ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                $acceptKey,
                new FileModerationStateUpdateSignalData(null),
            );
        }

        $token = pathinfo($storedName, PATHINFO_FILENAME);
        $event = Hilos::$db->events->actions->addFile(
            userId: $result->userId,
            originalFilename: $result->originalFilename,
            mimeType: $result->mimeType,
            size: $result->size,
            downloadToken: $token,
            storedName: $storedName,
        );

        $this->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(full: [DbChatContext::events => Events::fromSingleItem($event)])),
        );
    }

    /**
     * After the last binary chunk: move tmp → quarantine, clear upload session and progress UI,
     * enter `moderating` phase, send {@see ChatSignalConstants::FILE_UPLOAD_COMPLETE},
     * and dispatch {@see ChatSignalConstants::MODERATE_FILE_REQUEST} to the moderator agent.
     *
     * @param string $acceptKey WebSocket connection id
     * @throws \Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException
     */
    private function completeFileUpload(string $acceptKey): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }
        $conn = Hilos::$rt->connections[$acceptKey];
        if ($conn->fileSessionUploadId === null) {
            return;
        }
        $uploadId = $conn->fileSessionUploadId;
        $tmpIndex = $conn->fileSessionQuarantineBasename;
        $declaredSize = $conn->fileSessionDeclaredSize;
        $originalFilename = $conn->fileSessionOriginalFilename;
        $mimeType = $conn->fileSessionMimeType;
        $userId = $conn->userId;

        $ext = FsFile::extensionForMime($mimeType);
        $storedName = $uploadId . $ext;
        try {
            Hilos::$fs->quarantine->createFromTmp($storedName, $tmpIndex);
        } catch (FsException $e) {
            Logger::logAgentError($this->getId(), "Cannot move tmp to quarantine: {$e->getMessage()}");
            $this->failFileUploadSession($acceptKey, 'storage_error');

            return;
        }

        $conn->actions->clearBinaryUploadSessionAndProgressUi();
        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
            $acceptKey,
            new FileUploadProgressUpdateSignalData(null),
        );

        $conn->actions->enterFileModerationPending($originalFilename, $declaredSize);
        $modPhase = $conn->fileModPhase;
        $this->sendToUser(
            ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
            $acceptKey,
            new FileModerationStateUpdateSignalData(
                $modPhase === null ? null : [
                    'phase' => $modPhase,
                    'filename' => $conn->fileModFilename,
                    'uploadedBytes' => $conn->fileModUploadedBytes,
                    'totalBytes' => $conn->fileModTotalBytes,
                    'reason' => $conn->fileModReason !== '' ? $conn->fileModReason : null,
                    'updatedAt' => $conn->fileModUpdatedAt,
                ],
            ),
        );

        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_COMPLETE,
            $acceptKey,
            new FileUploadCompleteSignalData(
                uploadId: $uploadId,
                filename: $originalFilename,
            ),
        );

        $synthetic = sprintf(
            'User uploads a file for chat: name=%s, mime=%s, size=%d bytes. Approve only if appropriate for a public chat.',
            $originalFilename,
            $mimeType,
            $declaredSize,
        );

        $this->sendToAgent(
            ChatSignalConstants::MODERATE_FILE_REQUEST,
            new ModerationFileRequestSignalData(
                acceptKey: $acceptKey,
                userId: $userId,
                quarantineBasename: $storedName,
                originalFilename: $originalFilename,
                mimeType: $mimeType,
                size: $declaredSize,
                syntheticMessage: $synthetic,
            ),
        );
    }

    /**
     * Abort the active upload: delete tmp file, clear session/progress runtime, notify the client.
     *
     * @param string $acceptKey WebSocket connection id
     * @param string $reason Short code forwarded in {@see FileUploadInvalidSignalData}
     * @throws \Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException When the connections actions collection name is null
     */
    private function failFileUploadSession(string $acceptKey, string $reason): void
    {
        Hilos::$fs->tmp[Hilos::$rt->connections[$acceptKey]->fileSessionQuarantineBasename]->unlink();
        Hilos::$rt->connections[$acceptKey]->actions->clearBinaryUploadSessionAndProgressUi();
        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_INVALID,
            $acceptKey,
            new FileUploadInvalidSignalData($reason),
        );
    }

    /**
     * Send {@see ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE} to this WebSocket connection at most every
     * {@see self::FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC} unless `$force` is true or the upload is complete
     * (`uploadedBytes` >= `totalBytes` > 0).
     *
     * Throttle clock is primed when {@see ChatSignalConstants::FILE_UPLOAD_READY} is sent (same baseline the client
     * uses for 0 / total). Reads progress from {@see RuntimeConnection::$fileProgressFilename} and related fields.
     *
     * @param string $acceptKey WebSocket connection id
     * @param bool $force When true, send immediately (e.g. last chunk), bypassing the min-interval throttle
     * @throws \Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException When collection name is null for connection actions.
     */
    private function sendFileUploadProgressUpdateThrottled(string $acceptKey, bool $force): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: throttle acceptKey={$acceptKey} abort_no_state",
            );

            return;
        }
        $progressName = Hilos::$rt->connections[$acceptKey]->fileProgressFilename;
        if ($progressName === null) {
            Logger::logAgentInfo(
                $this->getId(),
                "upload_progress: throttle acceptKey={$acceptKey} abort_no_progress_state",
            );

            return;
        }
        $uploaded = Hilos::$rt->connections[$acceptKey]->fileProgressUploadedBytes;
        $total = Hilos::$rt->connections[$acceptKey]->fileProgressTotalBytes;
        $isComplete = $total > 0 && $uploaded >= $total;
        $now = microtime(true);
        $last = Hilos::$rt->connections[$acceptKey]->uploadProgressLastSentAt;
        $elapsed = $last > 0.0 ? ($now - $last) : null;
        $minSec = self::FILE_UPLOAD_PROGRESS_MIN_INTERVAL_SEC;
        if (!$force && !$isComplete && $elapsed !== null && $elapsed < $minSec) {
            return;
        }
        Hilos::$rt->connections[$acceptKey]->actions->noteUploadProgressSentAt($now);
        $this->sendToUser(
            ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
            $acceptKey,
            new FileUploadProgressUpdateSignalData([
                'filename' => $progressName,
                'uploadedBytes' => $uploaded,
                'totalBytes' => $total,
            ]),
        );
    }
}

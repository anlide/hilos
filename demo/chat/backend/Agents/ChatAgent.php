<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Agents\DTO\ImpersonateStopActionDTO;
use Demo\Chat\Agents\DTO\LogoutActionDTO;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Core\Router\DTO\OAuthBindSessionSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\View\Item\Session;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
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

    public const array AGENT_SIGNALS = [
        ChatSignalConstants::BOT_MESSAGE => BotMessageSignalData::class,
        ChatSignalConstants::OAUTH_BIND_SESSION => OAuthBindSessionSignalData::class,
    ];

    public const array AGENT_ACTIONS = [
        ChatSignalConstants::LOGOUT => LogoutActionDTO::class,
        ChatSignalConstants::IMPERSONATE_STOP => ImpersonateStopActionDTO::class,
    ];

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
        $this->registerDbTruthSource(ChatDbContext::sessions);
        $this->registerRtTruthSource(ChatRtContext::connections);
        $this->registerRtTruthSource(ChatRtContext::userStates);
        $this->registerRtTruthSource(ChatRtContext::attachmentDrafts);

        Hilos::$db->events->actions->addChatStarted();
    }

    /**
     * Handle a CLI command routed to the chat agent.
     *
     * `echo` echoes the request payload back (the admin-grant transport probe).
     * `setAdmin`, `impersonateStart`, and `impersonateStop` each dispatch to a
     * dedicated handler that replies ok or with an error message. Any other
     * command name yields an error reply.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        switch ($data->command) {
            case ChatCommandConstants::ECHO:
                $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, $data->payload));

                return;

            case ChatCommandConstants::SET_ADMIN:
                $this->handleSetAdmin($data);

                return;

            case ChatCommandConstants::IMPERSONATE_START:
                $this->handleImpersonateStart($data);

                return;

            case ChatCommandConstants::IMPERSONATE_STOP:
                $this->handleImpersonateStop($data);

                return;

            default:
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));

                return;
        }
    }

    /**
     * Flips the admin flag of the user named in the payload and replies with the
     * resulting state, or an error when the user is unknown or the write fails.
     *
     * @param CommandRequestDTO $data Command request carrying the target user id and admin flag
     * @throws HilosException On an unexpected database failure outside the caught write path
     */
    private function handleSetAdmin(CommandRequestDTO $data): void
    {
        $userId = (int)($data->payload[ChatCommandConstants::FIELD_USER_ID] ?? 0);
        $admin = (bool)($data->payload[ChatCommandConstants::FIELD_ADMIN] ?? false);
        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "No such user: {$userId}"));

            return;
        }

        try {
            $user->actions->setAdmin($admin);
        } catch (HilosException $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            ChatCommandConstants::FIELD_USER_ID => $userId,
            ChatCommandConstants::FIELD_ADMIN => $admin,
        ]));
    }

    /**
     * CLI command wrapper over {@see self::startImpersonation()}: parses the token
     * and target from the command payload, runs the shared core, and replies ok
     * with the effective user and the recorded admin, or an error message on a
     * guard rejection.
     *
     * @param CommandRequestDTO $data Command request carrying the session token and target user id
     * @throws HilosException On an unexpected database or runtime failure outside the caught core path
     */
    private function handleImpersonateStart(CommandRequestDTO $data): void
    {
        $sessionToken = (string)($data->payload[ChatCommandConstants::FIELD_SESSION_TOKEN] ?? '');
        $targetUserId = (int)($data->payload[ChatCommandConstants::FIELD_TARGET_USER_ID] ?? 0);

        try {
            $this->startImpersonation($sessionToken, $targetUserId);
        } catch (ValidationException $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            ChatCommandConstants::FIELD_SESSION_TOKEN => $sessionToken,
            ChatCommandConstants::FIELD_EFFECTIVE_USER_ID => $targetUserId,
            ChatCommandConstants::FIELD_IMPERSONATOR => Hilos::$db->sessions->findByToken($sessionToken)?->impersonatorUserId,
        ]));
    }

    /**
     * CLI command wrapper over {@see self::stopImpersonation()}: parses the token
     * from the command payload, captures the admin to restore before the marker is
     * cleared, runs the shared core, and replies ok with the restored effective
     * user, or an error message on a guard rejection.
     *
     * @param CommandRequestDTO $data Command request carrying the session token
     * @throws HilosException On an unexpected database or runtime failure outside the caught core path
     */
    private function handleImpersonateStop(CommandRequestDTO $data): void
    {
        $sessionToken = (string)($data->payload[ChatCommandConstants::FIELD_SESSION_TOKEN] ?? '');

        // Captured before the core clears the marker; the restored effective user.
        $impersonatorId = Hilos::$db->sessions->findByToken($sessionToken)?->impersonatorUserId;

        try {
            $this->stopImpersonation($sessionToken);
        } catch (ValidationException $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            ChatCommandConstants::FIELD_SESSION_TOKEN => $sessionToken,
            ChatCommandConstants::FIELD_EFFECTIVE_USER_ID => $impersonatorId,
        ]));
    }

    /**
     * Starts impersonation: an admin session assumes another user's identity.
     *
     * Shared core behind both the CLI command (HIL-166) and the admin users-table
     * page-action ({@see \Demo\Chat\Pages\AdminUsersPage}). Guards, in order: the
     * session must exist; its current user must be an admin (an anonymous or
     * non-admin session is rejected — which also blocks a second start once
     * impersonating a non-admin target); it must not already be impersonating (no
     * nesting); the target must exist and differ from the admin. The admin id is
     * recorded on the impersonator marker BEFORE the rebind, so the handshake
     * response {@see self::authenticateSession()} re-emits already reflects the
     * impersonation (its `impersonatedBy` slot names the admin). The rebind reuses
     * authenticateSession (identical to a login, so every guard/ownership path sees
     * the target). An audit line records the transition.
     *
     * @param string $sessionToken Session cookie token of the acting admin session
     * @param int $targetUserId User id to impersonate
     * @throws ValidationException When a guard rejects the request
     * @throws HilosException On database or runtime failure
     */
    public function startImpersonation(string $sessionToken, int $targetUserId): void
    {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            throw new ValidationException('No such session');
        }

        $adminId = $session->userId;
        $admin = $adminId !== null ? (Hilos::$db->users[$adminId] ?? null) : null;
        if ($admin === null || !$admin->admin) {
            throw new ValidationException('Session is not an admin session');
        }

        if ($session->impersonatorUserId !== null) {
            throw new ValidationException('Already impersonating; stop impersonating first');
        }

        if ((Hilos::$db->users[$targetUserId] ?? null) === null) {
            throw new ValidationException("No such user: {$targetUserId}");
        }

        if ($targetUserId === $adminId) {
            throw new ValidationException('Cannot impersonate yourself');
        }

        // Marker BEFORE rebind: authenticateSession re-emits the handshake response,
        // which reads the marker to fill impersonatedBy — so it must already be set.
        $session->actions->setImpersonator($adminId);
        $this->authenticateSession($sessionToken, $targetUserId);

        $this->logAgentInfo('impersonate_start ' . json_encode([
            'event' => 'impersonate_start',
            'admin' => $adminId,
            'target' => $targetUserId,
            'session' => $session->id,
        ]));
    }

    /**
     * Stops impersonation: reverts an impersonating session back to its admin.
     *
     * Shared core behind both the CLI command (HIL-166) and the shell agent-action.
     * The session must exist and must currently be impersonating. The marker is
     * cleared BEFORE the rebind, so the handshake response
     * {@see self::authenticateSession()} re-emits reflects the session as no longer
     * impersonated (its `impersonatedBy` slot clears). The admin to restore comes
     * from the marker. An audit line records the transition, capturing the vacated
     * target before the rebind restores the admin.
     *
     * @param string $sessionToken Session cookie token of the impersonating session
     * @throws ValidationException When the session is missing or not impersonating
     * @throws HilosException On database or runtime failure
     */
    public function stopImpersonation(string $sessionToken): void
    {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            throw new ValidationException('No such session');
        }

        $impersonatorId = $session->impersonatorUserId;
        if ($impersonatorId === null) {
            throw new ValidationException('Session is not impersonating');
        }

        // The target the session was acting as, captured before the rebind restores
        // the admin — recorded on the audit line as the identity vacated.
        $vacatedUserId = $session->userId;

        // Marker cleared BEFORE rebind: the re-emitted handshake response then reads
        // no marker and clears impersonatedBy — the inverse of startImpersonation.
        $session->actions->setImpersonator(null);
        $this->authenticateSession($sessionToken, $impersonatorId);

        $this->logAgentInfo('impersonate_stop ' . json_encode([
            'event' => 'impersonate_stop',
            'admin' => $impersonatorId,
            'restoredUser' => $impersonatorId,
            'vacatedUser' => $vacatedUserId,
            'session' => $session->id,
        ]));
    }

    /**
     * Resolves the daemon-carried session token to a session row (creating an
     * anonymous one when the cookie is new), registers the connection under that
     * session, and sends the handshake response — the current user for an
     * authenticated session, or an anonymous response that leaves the frontend
     * current user null.
     *
     * A session is anonymous (no user) until login/register upgrades it through
     * {@see self::authenticateSession}; no visitor is auto-registered as a user.
     * Runtime presence and per-user state are ensured only for an authenticated
     * session.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws EmptyValueException When the session token is empty
     * @throws InvalidFormatException When the session token is not a 32-character lowercase hex string
     * @throws HilosException On database or runtime failure
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        // The daemon resolved the session token on the 101 (the client's cookie
        // or a freshly issued one) and carried it on the handshake DTO. Validate
        // inside the ValidationException family so the worker dispatcher contains
        // a bad token instead of crashing.
        $sessionToken = $data->sessionToken;
        if ($sessionToken === '') {
            throw new EmptyValueException('session token is required');
        }

        if (preg_match(self::SESSION_TOKEN_PATTERN, $sessionToken) !== 1) {
            throw new InvalidFormatException(
                'session token must be a 32-character lowercase hex token',
            );
        }

        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            $session = Hilos::$db->sessions->actions->createAnonymous($sessionToken);
        } else {
            $session->actions->touch();
        }
        $userId = $session->userId;

        Hilos::$rt->connections->actions->register($data->acceptKey, $userId, $sessionToken);

        if ($userId !== null) {
            Hilos::$ac?->identifyBrowserSessionUser($sessionToken, $userId);
            Hilos::$rt->userStates->actions->ensure($userId);
        }

        $this->sendToUser(
            ChatSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            $this->handshakeResponseFor($session),
        );
    }

    /**
     * Builds the handshake response describing a session's current identity,
     * filling the impersonatedBy slot from the session's impersonator marker.
     *
     * An anonymous or missing session yields the anonymous response that clears the
     * frontend current user. An impersonated session additionally carries the
     * impersonating admin, so the shell shows its banner; a plain authenticated
     * session leaves the impersonator fields null.
     *
     * @param ?Session $session Session to describe, or null for an anonymous response
     * @return HandshakeResponseSignalData Handshake response for the session
     */
    private function handshakeResponseFor(?Session $session): HandshakeResponseSignalData
    {
        $userId = $session?->userId;
        if ($session === null || $userId === null) {
            return new HandshakeResponseSignalData();
        }

        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            return new HandshakeResponseSignalData();
        }

        $impersonatorId = $session->impersonatorUserId;
        $impersonator = $impersonatorId !== null ? (Hilos::$db->users[$impersonatorId] ?? null) : null;

        return new HandshakeResponseSignalData(
            selfId: (int)$user->id,
            selfName: $user->name,
            impersonatorId: $impersonator !== null ? (int)$impersonator->id : null,
            impersonatorName: $impersonator?->name,
        );
    }

    /**
     * Authenticates a live session: binds it to a user, re-points the session's
     * active connections to that user, and re-emits the handshake response so
     * their frontends populate the current user.
     *
     * The upgrade seam login (HIL-162) and register (HIL-164) call to promote a
     * session; the symmetric downgrade (logout) is HIL-163. A no-op when the token
     * has no session row.
     *
     * @param string $sessionToken Session cookie token to authenticate
     * @param int $userId Durable user id to bind the session to
     * @throws HilosException On database or runtime failure
     */
    public function authenticateSession(string $sessionToken, int $userId): void
    {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            return;
        }

        $session->actions->bindUser($userId);
        Hilos::$rt->userStates->actions->ensure($userId);
        Hilos::$ac?->identifyBrowserSessionUser($sessionToken, $userId);

        $response = $this->handshakeResponseFor($session);

        foreach (Hilos::$rt->connections->getStateCollection()->findAllBySessionToken($sessionToken) as $stateConnection) {
            Hilos::$rt->connections[$stateConnection->acceptKey]?->actions->bindUser($userId);
            $this->sendToUser(ChatSignalConstants::HANDSHAKE_RESPONSE, $stateConnection->acceptKey, $response);
        }
    }

    /**
     * Reverts a live session to anonymous: nulls the session user, re-points the
     * session's active connections to no user, and re-emits the anonymous handshake
     * response so their frontends clear the current user. The inverse of
     * {@see self::authenticateSession()}.
     *
     * The session ROW and token are kept — the session simply becomes anonymous
     * again (per the HIL-161 model); token rotation and "log out all devices" are
     * out of scope. A no-op when the token has no session row or is already
     * anonymous (a guest invoking logout is ignored). Presence follows the
     * connection re-point: a user with no other authenticated connection drops
     * offline through the standard connection sync, so no per-user state is removed
     * here. Analytics session→user identity is left as set; there is no de-identify
     * seam and it is re-bound on the next login.
     *
     * @param string $sessionToken Session cookie token to revert to anonymous
     * @throws HilosException On database or runtime failure
     */
    public function deauthenticateSession(string $sessionToken): void
    {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null || $session->userId === null) {
            return;
        }

        $session->actions->unbindUser();

        $response = new HandshakeResponseSignalData();
        foreach (Hilos::$rt->connections->getStateCollection()->findAllBySessionToken($sessionToken) as $stateConnection) {
            Hilos::$rt->connections[$stateConnection->acceptKey]?->actions->bindUser(null);
            $this->sendToUser(ChatSignalConstants::HANDSHAKE_RESPONSE, $stateConnection->acceptKey, $response);
        }
    }

    /**
     * Routes agent-owned client actions. Logout and impersonation-stop are
     * page-independent (their controls live in the app shell — and while
     * impersonating the effective user is the non-admin target, so no admin page is
     * guaranteed), so they arrive here rather than through a page.
     *
     * @param string $acceptKey Acting connection accept key
     * @param string $action Owned action name from AGENT_ACTIONS
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this agent
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ValidationException When impersonation-stop is invoked on a non-impersonating session
     * @throws HilosException When logout or impersonation-stop exposes database or runtime failure
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::LOGOUT:
                if (!$dto instanceof LogoutActionDTO) {
                    throw new InvalidActionPayloadException($action, LogoutActionDTO::class, $dto);
                }
                $this->handleLogout($acceptKey);

                return;

            case ChatSignalConstants::IMPERSONATE_STOP:
                if (!$dto instanceof ImpersonateStopActionDTO) {
                    throw new InvalidActionPayloadException($action, ImpersonateStopActionDTO::class, $dto);
                }
                $this->handleImpersonateStopAction($acceptKey);

                return;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Reverts the acting connection's session to anonymous.
     *
     * Resolves the session from the acting connection; a stale accept key or a
     * connection with no token is a no-op.
     *
     * @param string $acceptKey Acting connection accept key
     * @throws HilosException When session teardown exposes database or runtime failure
     */
    private function handleLogout(string $acceptKey): void
    {
        $sessionToken = Hilos::$rt->connections[$acceptKey]?->sessionToken;
        if ($sessionToken === null || $sessionToken === '') {
            return;
        }

        $this->deauthenticateSession($sessionToken);
    }

    /**
     * Reverts the acting connection's impersonating session back to its admin.
     *
     * Resolves the session from the acting connection; a stale accept key or a
     * connection with no token is a no-op. A guard rejection (the session is not
     * impersonating) surfaces as a ValidationException the worker dispatcher logs —
     * the banner control is shown only while impersonating, so this is unreachable
     * from the shell.
     *
     * @param string $acceptKey Acting connection accept key
     * @throws ValidationException When the resolved session is not impersonating
     * @throws HilosException When impersonation teardown exposes database or runtime failure
     */
    private function handleImpersonateStopAction(string $acceptKey): void
    {
        $sessionToken = Hilos::$rt->connections[$acceptKey]?->sessionToken;
        if ($sessionToken === null || $sessionToken === '') {
            return;
        }

        $this->stopImpersonation($sessionToken);
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
     * @throws HilosException On bot message publish failure
     * @throws LogicException On payload type mismatch, or if event id is null after sync
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatSignalConstants::MODERATION_RESULT:
            case ChatSignalConstants::RENAME_MODERATION_RESULT:
                return;
            case ChatSignalConstants::BOT_MESSAGE:
                if (!$data->data instanceof BotMessageSignalData) {
                    throw new LogicException(
                        ChatSignalConstants::BOT_MESSAGE . ' payload must be ' . BotMessageSignalData::class,
                    );
                }
                $this->handleBotMessage($data->data);
                return;
            case ChatSignalConstants::OAUTH_BIND_SESSION:
                if (!$data->data instanceof OAuthBindSessionSignalData) {
                    throw new LogicException(
                        ChatSignalConstants::OAUTH_BIND_SESSION . ' payload must be ' . OAuthBindSessionSignalData::class,
                    );
                }
                $this->authenticateSession($data->data->sessionToken, $data->data->userId);
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
     * @throws LogicException If event id is null after sync
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

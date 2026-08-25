<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Agents\DTO\AccountMergeSummary;
use Demo\Chat\Agents\DTO\DismissSessionAckActionDTO;
use Demo\Chat\Agents\DTO\ImpersonateStopActionDTO;
use Demo\Chat\Agents\DTO\LogoutActionDTO;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\AccountMergeSignalData;
use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Registration\RegistrationReservationSweeper;
use Hilos\Auth\Session\HilosSessionHost;
use Hilos\Auth\Session\HilosSessionHostInterface;
use Hilos\Auth\Session\SessionAck;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\Database;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Database\View\Item\Session;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;

/**
 * Monopolistic chat worker for chat events, users, runtime connections, WebSocket lifecycle, and bot messages.
 *
 * On start, registers chat database tables and runtime collections as truth sources.
 *
 * The holder of this project's sessions ({@see HilosSessionHostInterface}), which is a
 * declaration and nothing more: the seven methods of the contract come from the trait
 * below, and saying so out loud is what lets the framework find the holder by type.
 */
final class ChatAgent extends AbstractAgent implements HilosSessionHostInterface
{
    use HilosSessionHost;

    public const string AGENT_TYPE = AgentType::CHAT;

    // The five frames of the session-holder contract are spread in whole: they are the
    // library's half of every sign-in that ends in a signed-in person, and the holder is
    // this agent (HIL-622). Listing them by hand would be this project restating a
    // contract the framework already states.
    public const array AGENT_SIGNALS = [
        ChatSignalConstants::BOT_MESSAGE => BotMessageSignalData::class,
        ChatSignalConstants::ACCOUNT_MERGE_REQUEST => AccountMergeSignalData::class,
        ...HilosSessionHostInterface::SESSION_HOST_SIGNALS,
    ];

    // The echo exists only to prove the command channel end to end, so it carries the
    // test-only flag: a production node refuses it at the socket and never parks it.
    public const array AGENT_COMMANDS = [
        ChatCommandConstants::ECHO => [AgentCommandConfigKey::TEST_ONLY => true],
        ChatCommandConstants::SET_ADMIN,
        ChatCommandConstants::IMPERSONATE_START,
        ChatCommandConstants::IMPERSONATE_STOP,
        ChatCommandConstants::ACCOUNT_MERGE,
    ];

    public const array AGENT_ACTIONS = [
        ChatSignalConstants::LOGOUT => LogoutActionDTO::class,
        HilosSignalConstants::HILOS_DISMISS_SESSION_ACK => DismissSessionAckActionDTO::class,
        ChatSignalConstants::IMPERSONATE_STOP => ImpersonateStopActionDTO::class,
    ];

    /**
     * Registers chat truth sources, arms the abandoned-registration sweep, and records
     * chat startup.
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
        $this->registerRtTruthSource(StateRegistrationWaiter::RT_COLLECTION);
        $this->registerRtTruthSource(StateRecoveryWaiter::RT_COLLECTION);
        $this->startSessionRotations();
        $this->startPendingRegistrationSweep();

        Hilos::$db->events->actions->addChatStarted();
    }

    /**
     * Drops login rotations whose ticket is past its moment (HIL-582) and the sign-in
     * surfaces whose connection is gone - parked on a registration code (HIL-415) or on
     * a password-recovery one (HIL-416).
     *
     * Also clears, on its own schedule, the registrations nobody came back to finish
     * (HIL-612) - the one part of this tick that reads the database, which is why it is
     * behind a cron rule instead of running every pass.
     *
     * The rest of the tick walks over in-memory collections that hold one row per login in
     * the last thirty seconds and one per sign-in surface parked on a confirmation code, so
     * it is measured in microseconds and never touches the database or the network - which
     * is what the tick rule requires of it. Each waiter walk is skipped outright while
     * nobody is registering or recovering, which is almost always.
     *
     * @throws HilosException On runtime failure
     */
    public function onTick(): void
    {
        $this->sweepSessionRotations();
        $this->sweepPendingRegistrations();
        $this->sweepRegistrationWaiters();
        $this->sweepRecoveryWaiters();
    }

    /**
     * Handle a CLI command routed to the chat agent.
     *
     * `test:command:echo` echoes the request payload back (the admin-grant transport probe).
     * `setAdmin`, `impersonateStart`, `impersonateStop`, and `accountMerge` each
     * dispatch to a dedicated handler that replies ok or with an error message.
     * Any other command name yields an error reply.
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

            case ChatCommandConstants::ACCOUNT_MERGE:
                $this->handleAccountMergeCommand($data);

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

        // The grant changes what this user's shell may show, and the shell learns
        // its identity from the handshake response alone. Without this the change
        // reaches the user only on their next reload, and until then they are an
        // admin who is shown no way in — the same silence a revoke would leave.
        $this->broadcastHandshakeResponseToUser($userId);
        // The other half of the same duty (HIL-621): the shell now knows what it may
        // show, and every page this user has open is still answering the verdict it
        // was subscribed with. Chat routes its own setAdmin command, so the framework
        // handler never runs and this call cannot be inherited from it.
        PageAccessReassessment::forUser($userId);

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            ChatCommandConstants::FIELD_USER_ID => $userId,
            ChatCommandConstants::FIELD_ADMIN => $admin,
        ]));
    }

    /**
     * CLI command wrapper over {@see self::startImpersonation()}: parses the token
     * and target from the command payload, runs the shared core, and replies ok
     * with the effective user and the recorded admin, or an error message on a
     * guard rejection or a database / runtime failure. The reply lookup runs inside
     * the caught path too: a command failure must not reach the worker loop, which
     * catches neither HilosException nor its database children.
     *
     * @param CommandRequestDTO $data Command request carrying the session token and target user id
     */
    private function handleImpersonateStart(CommandRequestDTO $data): void
    {
        $sessionToken = (string)$data->payload[ChatCommandConstants::FIELD_SESSION_TOKEN];
        $targetUserId = (int)($data->payload[ChatCommandConstants::FIELD_TARGET_USER_ID] ?? 0);

        try {
            $this->startImpersonation($sessionToken, $targetUserId, null);
            $impersonatorId = Hilos::$db->sessions->findByToken($sessionToken)?->impersonatorUserId;
        } catch (HilosException $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            ChatCommandConstants::FIELD_SESSION_TOKEN => $sessionToken,
            ChatCommandConstants::FIELD_EFFECTIVE_USER_ID => $targetUserId,
            ChatCommandConstants::FIELD_IMPERSONATOR => $impersonatorId,
        ]));
    }

    /**
     * CLI command wrapper over {@see self::stopImpersonation()}: parses the token
     * from the command payload, captures the admin to restore before the marker is
     * cleared, runs the shared core, and replies ok with the restored effective
     * user, or an error message on a guard rejection or a database / runtime
     * failure. The marker lookup runs inside the caught path too: a command failure
     * must not reach the worker loop, which catches neither HilosException nor its
     * database children.
     *
     * @param CommandRequestDTO $data Command request carrying the session token
     */
    private function handleImpersonateStop(CommandRequestDTO $data): void
    {
        $sessionToken = (string)$data->payload[ChatCommandConstants::FIELD_SESSION_TOKEN];

        try {
            // Captured before the core clears the marker; the restored effective user.
            $impersonatorId = Hilos::$db->sessions->findByToken($sessionToken)?->impersonatorUserId;
            $this->stopImpersonation($sessionToken, null);
        } catch (HilosException $e) {
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
     * page-action ({@see AdminUsersPage}). Guards, in order: the
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
     * @param ?string $initiatorAcceptKey Accept key of the admin's connection, or null for the CLI path
     * @throws ValidationException When a guard rejects the request
     * @throws HilosException On database or runtime failure
     */
    public function startImpersonation(string $sessionToken, int $targetUserId, ?string $initiatorAcceptKey): void
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
        $this->authenticateSession($sessionToken, $targetUserId, $initiatorAcceptKey);

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
     * @param ?string $initiatorAcceptKey Accept key of the requesting connection, or null for the CLI path
     * @throws ValidationException When the session is missing or not impersonating
     * @throws HilosException On database or runtime failure
     */
    public function stopImpersonation(string $sessionToken, ?string $initiatorAcceptKey): void
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
        $this->authenticateSession($sessionToken, $impersonatorId, $initiatorAcceptKey);

        $this->logAgentInfo('impersonate_stop ' . json_encode([
            'event' => 'impersonate_stop',
            'admin' => $impersonatorId,
            'restoredUser' => $impersonatorId,
            'vacatedUser' => $vacatedUserId,
            'session' => $session->id,
        ]));
    }

    /**
     * CLI command wrapper over {@see self::handleAccountMerge()}: parses the
     * survivor and loser ids from the command payload, runs the merge, and replies
     * ok with the transfer counts, or an error message on a guard rejection or a
     * database / truth-source failure (the merge has already rolled back any
     * partial write). Reached from the `account:merge` CLI command over the daemon
     * command channel; authorization is the channel's (operator-only) job.
     *
     * An absent password key is the operator not naming a fate, which is a legitimate
     * request and not a missing field (HIL-692) — the merge refuses on its own if the
     * two accounts turn out to need one. An unreadable value is the command's to reject
     * before it sends, so a value arriving here has already been through the enum.
     *
     * @param CommandRequestDTO $data Command request carrying the survivor and loser user ids
     */
    private function handleAccountMergeCommand(CommandRequestDTO $data): void
    {
        $survivorId = (int)($data->payload[ChatCommandConstants::FIELD_SURVIVOR_USER_ID] ?? 0);
        $loserId = (int)($data->payload[ChatCommandConstants::FIELD_LOSER_USER_ID] ?? 0);
        // external-boundary: an operator may omit the fate; the merge refuses if it needed one
        $passwordFate = PasswordFate::tryFrom((string)($data->payload[ChatCommandConstants::FIELD_PASSWORD_FATE] ?? ''));

        try {
            $summary = $this->handleAccountMerge($survivorId, $loserId, $passwordFate);
        } catch (HilosException $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, $summary->toArray()));
    }

    /**
     * Runs an admin-requested account merge and acks the initiating connection (HIL-378).
     *
     * The merge executes here, not on the requesting Hilos user page agent,
     * because this agent owns the users, messages, and sessions truth sources
     * plus the force-logout mechanics. A guard rejection or a database /
     * truth-source failure — after {@see self::handleAccountMerge()} has rolled
     * back any partial write — becomes a one-to-one ACCOUNT_MERGE_FAIL ack
     * carrying the error text; success acks with ACCOUNT_MERGE_SUCCESS and the
     * transfer counts.
     *
     * @param AccountMergeSignalData $request Survivor, loser, and initiator accept key
     */
    private function handleAccountMergeRequest(AccountMergeSignalData $request): void
    {
        try {
            $summary = $this->handleAccountMerge($request->survivorUserId, $request->loserUserId, null);
        } catch (HilosException $e) {
            $this->sendToUser(
                ChatSignalConstants::ACCOUNT_MERGE_FAIL,
                $request->acceptKey,
                new ActionFailSignalData($e->getMessage()),
            );

            return;
        }

        $this->sendToUser(
            ChatSignalConstants::ACCOUNT_MERGE_SUCCESS,
            $request->acceptKey,
            new ActionSuccessSignalData($summary->toArray()),
        );
    }

    /**
     * Merges a loser account into a survivor and returns what moved (HIL-378).
     *
     * The one orchestration core behind both merge entry points (the CLI
     * `account:merge` command and the admin-UI table action, wired in later
     * slices): the survivor absorbs the loser's sign-in identities and chat
     * messages, then the loser is tombstoned. It runs on the leader, which owns
     * every table the merge touches (identities re-point is a plain framework
     * primitive; messages and the user row are chat truth sources).
     *
     * Validation happens before any write: the two ids must differ, both users
     * must exist, and neither may already be a merge loser (`mergedInto` set) —
     * any failure throws before a transaction opens, so a caller reports a
     * generic error with nothing half-written. Authorizing the caller as an admin
     * is the entry point's job (the CLI channel is admin-only; the table action is
     * admin-gated), matching how the raw ids arrive here already trusted.
     *
     * One guard is about the merge rather than about the ids (HIL-692): an account holds
     * at most one password, so two accounts that each have one cannot both be right and
     * this refuses until the operator says which stays. It refuses only there — while one
     * of the two has a password at most, that one survives and the command keeps the shape
     * it always had. What comes back is the OUTCOME and not the request: the account is
     * asked afterwards which password it now carries, so a fate naming an account that had
     * none reports the truth ({@see PasswordFate::NONE}) rather than the word that was
     * typed.
     *
     * The transfer is one explicit transaction so a half-merged account can never
     * survive a mid-way failure: identity re-point, message re-point, and the
     * loser tombstone either all commit or all roll back. Ordering is free — the
     * loser is tombstoned (row kept), never deleted, so no foreign-key cascade can
     * fire. The transferred messages become visible to viewers on their own: the
     * message re-point moves each row through its object's sync, which broadcasts a
     * DB_SYNC_UPDATED that re-renders the authorship for every viewer. After commit
     * (outside the transaction) the loser's live sessions are forced to log out
     * through {@see self::killUserSessions()} so a moved account cannot keep acting.
     *
     * @param int $survivorId Survivor user id that absorbs the loser
     * @param int $loserId Loser user id folded into the survivor
     * @param ?PasswordFate $passwordFate Whose password to keep, or null when the operator named none
     * @return AccountMergeSummary Counts of what moved, and whose password the account kept
     * @throws ValidationException When a guard rejects the merge (bad ids, missing or already-merged user, two passwords and no fate)
     * @throws HilosException On database or truth-source failure (transaction rolled back)
     */
    public function handleAccountMerge(int $survivorId, int $loserId, ?PasswordFate $passwordFate): AccountMergeSummary
    {
        if ($survivorId === $loserId) {
            throw new ValidationException('Cannot merge a user into itself');
        }

        $survivor = Hilos::$db->users[$survivorId] ?? null;
        if ($survivor === null) {
            throw new ValidationException("No such user: {$survivorId}");
        }
        if ($survivor->mergedInto !== null) {
            throw new ValidationException("Survivor {$survivorId} is itself a merged account");
        }

        $loser = Hilos::$db->users[$loserId] ?? null;
        if ($loser === null) {
            throw new ValidationException("No such user: {$loserId}");
        }
        if ($loser->mergedInto !== null) {
            throw new ValidationException("Loser {$loserId} is already merged");
        }

        if ($passwordFate === null && Hilos::$db->identities->passwordFateNeeded($loserId, $survivorId)) {
            throw new ValidationException('Both accounts have a password: pass --password=survivor|loser|none');
        }

        $survivorPasswordId = Hilos::$db->identities->findPasswordByUser($survivorId)?->id;

        Database::transactionStart();
        try {
            $identitiesMoved = Hilos::$db->identities->rePointToUser($loserId, $survivorId, $passwordFate);
            $messagesMoved = Hilos::$db->eventMessages->actions->rePointAuthor($loserId, $survivorId);
            $loser->actions->tombstone($survivorId);
            Database::transactionCommit();
        } catch (HilosException $e) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // A failing rollback would replace the error that made the merge fail
            }

            throw $e;
        }

        $keptPasswordId = Hilos::$db->identities->findPasswordByUser($survivorId)?->id;
        $passwordKept = match (true) {
            $keptPasswordId === null => PasswordFate::NONE,
            $keptPasswordId === $survivorPasswordId => PasswordFate::SURVIVOR,
            default => PasswordFate::LOSER,
        };

        $this->logAgentInfo('account_merge ' . json_encode([
            'event' => 'account_merge',
            'survivor' => $survivorId,
            'loser' => $loserId,
            'identitiesMoved' => $identitiesMoved,
            'messagesMoved' => $messagesMoved,
            ChatCommandConstants::FIELD_PASSWORD_KEPT => $passwordKept->value,
        ]));

        $this->killUserSessions($loserId);

        return new AccountMergeSummary($identitiesMoved, $messagesMoved, $passwordKept);
    }

    /**
     * Forces every live session of a merged loser to log out (HIL-378).
     *
     * The post-commit force-logout of account merge: a tombstoned loser must not
     * keep acting through an open session. Each of the loser's sessions is reverted
     * to anonymous through {@see HilosSessionHost::deauthenticateSession()} — the
     * same seam the logout control uses — which unbinds the session user, re-points
     * its live connections to no user, and re-emits the anonymous handshake so their
     * frontends clear the current user. The loser is deactivated (`block = 1` from
     * the tombstone), so re-authentication is impossible. Runs outside the merge
     * transaction: the transfer is already durable, and reverting sessions touches
     * runtime connections that must not participate in the DB rollback path.
     *
     * @param int $loserId Merged loser user id whose sessions are closed
     * @throws HilosException When session teardown exposes database or runtime failure
     */
    private function killUserSessions(int $loserId): void
    {
        foreach (Hilos::$db->sessions->findByUserId($loserId) as $session) {
            $this->deauthenticateSession($session->token);
        }
    }

    /**
     * Resolves the daemon-carried session token to a session row (creating an
     * anonymous one when the cookie is new), registers the connection under that
     * session, and sends the handshake response — the current user for an
     * authenticated session, or an anonymous response that leaves the frontend
     * current user null.
     *
     * A session is anonymous (no user) until login/register upgrades it through
     * {@see HilosSessionHost::authenticateSession()}; no visitor is auto-registered
     * as a user. Runtime presence and per-user state are ensured only for an
     * authenticated session. Token-to-session resolution (including the HIL-398
     * expiry drop) is delegated to {@see HilosSessionHost::resolveHandshakeSession()}.
     *
     * @param WebSocketHandshakeSignalDTO $data Accept key and the daemon-resolved session token
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws InvalidFormatException When the session token is not a 32-character lowercase hex string
     * @throws DuplicateValueException When a concurrent create already claimed a new token
     * @throws HilosException On database or runtime failure
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        // The daemon resolved the session token on the 101 (the client's cookie
        // or a freshly issued one) and carried it on the handshake DTO. Validate
        // inside the ValidationException family so the worker dispatcher contains
        // a bad token instead of crashing.
        $sessionToken = $data->sessionToken;
        SessionToken::ensureValid($sessionToken);

        $session = $this->resolveHandshakeSession($sessionToken);
        $userId = $session->userId;

        Hilos::$rt->connections->actions->register($data->acceptKey, $userId, $sessionToken);

        if ($userId !== null) {
            Hilos::$ac?->identifyBrowserSessionUser($sessionToken, $userId);
            Hilos::$rt->userStates->actions->ensure($userId);
        }

        $this->parkPendingRegistration($data->acceptKey, $session);
        $this->sendToUser(
            ChatSignalConstants::HANDSHAKE_RESPONSE,
            $data->acceptKey,
            // A socket born of a login's token rotation owes what the socket it replaced
            // still owed (HIL-423); every other handshake inherits null and says so.
            $this->handshakeResponse($session)->withPendingAck($this->inheritHandshakeAck($data)),
        );
    }

    /**
     * Builds the handshake response describing a session's current identity,
     * filling the impersonatedBy slot from the session's impersonator marker.
     *
     * The chat implementation of the {@see HilosSessionHost} hook: it reads the
     * display names from the chat user store and, while impersonating, the admin
     * behind the takeover. An anonymous or missing session yields the anonymous
     * response that clears the frontend current user; an impersonated session
     * additionally carries the impersonating admin, so the shell shows its banner,
     * while a plain authenticated session leaves the impersonator fields null.
     *
     * @param ?Session $session Session to describe, or null for an anonymous response
     * @return HandshakeResponseSignalData Handshake response for the session
     */
    protected function handshakeResponseFor(?Session $session): HandshakeResponseSignalData
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
            selfAdmin: $user->admin,
            impersonatorId: $impersonator !== null ? (int)$impersonator->id : null,
            impersonatorName: $impersonator?->name,
        );
    }

    /**
     * Re-sends the handshake response to every live connection of one user, so a
     * change to their identity reaches the shell without a reload.
     *
     * Built per connection rather than once: two connections of the same user can
     * sit on different sessions, and one of them may be impersonated, which the
     * response carries. A connection belonging to no session is skipped: there is
     * no session row to describe, and asking for one by a null token is a type
     * error rather than a miss.
     *
     * The pending ack rides along for the reason every other re-send carries it
     * (HIL-422): the response states the ack rather than amending it, so a rename or
     * an admin flip that went out without one would wipe an announcement the person
     * has not read yet.
     *
     * @param int $userId User whose connections are told
     */
    private function broadcastHandshakeResponseToUser(int $userId): void
    {
        $signalName = $this->handshakeResponseSignalName();
        foreach (Hilos::$rt->connections->forUser($userId) as $connection) {
            $sessionToken = $connection->sessionToken;
            if ($sessionToken === null) {
                continue;
            }

            $session = Hilos::$db->sessions->findByToken($sessionToken);
            $this->sendToUser(
                $signalName,
                $connection->acceptKey,
                $this->handshakeResponse($session)->withPendingAck($connection->pendingAck),
            );
        }
    }

    /**
     * Re-points one live chat connection's bound user through its runtime actions —
     * the {@see HilosSessionHost} hook. A missing connection is a no-op.
     *
     * @param string $acceptKey Connection accept key to re-point
     * @param ?int $userId User id to bind the connection to, or null for anonymous
     * @throws RtActionsCollectionNameNullException When the runtime connection collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent is not the connection truth source
     */
    protected function bindConnectionUser(string $acceptKey, ?int $userId): void
    {
        Hilos::$rt->connections[$acceptKey]?->actions->bindUser($userId);
    }

    /**
     * Writes one live chat connection's pending success ack — the {@see HilosSessionHost}
     * hook. A missing connection is a no-op, handled inside the collection action.
     *
     * @param string $acceptKey Connection accept key to mark
     * @param ?string $ack Ack the connection owes (a {@see SessionAck} value), or null to clear it
     * @throws RtActionsCollectionNameNullException When the runtime connection collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime connection state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent is not the connection truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    protected function markConnectionAck(string $acceptKey, ?string $ack): void
    {
        Hilos::$rt->connections->actions->markAck($acceptKey, $ack);
    }

    /**
     * Returns the chat handshake-response signal name the {@see HilosSessionHost}
     * seam emits under; the frontend routes on this project constant.
     *
     * @return string Chat handshake-response signal name
     */
    protected function handshakeResponseSignalName(): string
    {
        return ChatSignalConstants::HANDSHAKE_RESPONSE;
    }

    /**
     * Ensures the newly authenticated user's runtime presence state — the chat
     * override of the {@see HilosSessionHost} post-authenticate hook.
     *
     * @param int $userId Durable user id the session was bound to
     * @throws HilosException On runtime failure
     */
    protected function afterAuthenticate(int $userId): void
    {
        Hilos::$rt->userStates->actions->ensure($userId);
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
     * @return ?ActionReplyDTO Always null: none of the three answers with domain data
     * @throws AgentUnknownActionException When action is not supported by this agent
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ValidationException When impersonation-stop is invoked on a non-impersonating session
     * @throws HilosException When logout or impersonation-stop exposes database or runtime failure
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case ChatSignalConstants::LOGOUT:
                if (!$dto instanceof LogoutActionDTO) {
                    throw new InvalidActionPayloadException($action, LogoutActionDTO::class, $dto);
                }
                $this->handleLogout($acceptKey);

                return null;

            case HilosSignalConstants::HILOS_DISMISS_SESSION_ACK:
                if (!$dto instanceof DismissSessionAckActionDTO) {
                    throw new InvalidActionPayloadException($action, DismissSessionAckActionDTO::class, $dto);
                }
                $this->handleDismissSessionAck($acceptKey);

                return null;

            case ChatSignalConstants::IMPERSONATE_STOP:
                if (!$dto instanceof ImpersonateStopActionDTO) {
                    throw new InvalidActionPayloadException($action, ImpersonateStopActionDTO::class, $dto);
                }
                $this->handleImpersonateStopAction($acceptKey);

                return null;

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
     * Clears the success ack from the acting connection's session (HIL-422).
     *
     * The Continue button, arriving from whichever tab the person pressed it in. It
     * clears the whole session rather than the one socket, because the announcement was
     * put on the whole session: having read it once, nobody wants to dismiss it again in
     * the other tab.
     *
     * Resolves the session from the acting connection, exactly as {@see handleLogout()}
     * does; a stale accept key or a connection with no token is a no-op, and so is a
     * session that carries no ack — a second press, or two tabs pressing at once.
     *
     * @param string $acceptKey Acting connection accept key
     * @throws HilosException When clearing the ack exposes database or runtime failure
     */
    private function handleDismissSessionAck(string $acceptKey): void
    {
        $sessionToken = Hilos::$rt->connections[$acceptKey]?->sessionToken;
        if ($sessionToken === null || $sessionToken === '') {
            return;
        }

        $this->clearSessionAck($sessionToken);
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

        $this->stopImpersonation($sessionToken, $acceptKey);
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
     * Records chat shutdown and clears the chat runtime state that is the agent's own.
     *
     * The connections are not among it (HIL-664). Who is on the wire is the truth of the node
     * holding the sockets, and a stop does not close them: a freeze stops this agent and leaves
     * every tab connected, so emptying the collection here told the rest of the node the hall
     * was empty while it was full - and the restore that followed photographed nobody. The rows
     * outlive the agent now, and the sockets that died meanwhile are struck when it comes back.
     *
     * @throws HilosException On database or runtime cleanup failure
     */
    public function onStop(): void
    {
        Hilos::$db->events->actions->addChatStopped();
        Hilos::$rt->attachmentDrafts->actions->clearWithFiles();
        Hilos::$rt->userStates->actions->clear();
    }

    /**
     * Handles chat-owned cron cleanup for persisted history, transient attachment state,
     * and expired registration holds.
     *
     * The reservation sweep is the one that answers somebody: an expired hold means the
     * browser that made it is waiting for a code that can no longer confirm anything, so
     * each freed hold rolls back its own session the moment its row goes. Its own, and not
     * the address: since HIL-608 another browser may be registering the same address with
     * a hold of its own, and that one is still good.
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

            case ChatCronConstants::SWEEP_REGISTRATION_RESERVATIONS:
                foreach (new RegistrationReservationSweeper()->sweep() as $freed) {
                    $this->rollBackRegistrationWaiters(
                        $freed[ObjectRegistrationReservation::sessionToken],
                        $freed[ObjectRegistrationReservation::identifier],
                    );
                }

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
        // Asked of the map rather than of five case labels (HIL-685): the frames are spread
        // into AGENT_SIGNALS from that same map, and a second list of them here would be
        // this project restating a contract the framework already states - one that grew by
        // two the moment a wait learned to move.
        if (array_key_exists($name, HilosSessionHostInterface::SESSION_HOST_SIGNALS)) {
            $this->handleSessionHostFrame($data, $name);

            return;
        }

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
            case ChatSignalConstants::ACCOUNT_MERGE_REQUEST:
                if (!$data->data instanceof AccountMergeSignalData) {
                    throw new LogicException(
                        ChatSignalConstants::ACCOUNT_MERGE_REQUEST . ' payload must be ' . AccountMergeSignalData::class,
                    );
                }
                $this->handleAccountMergeRequest($data->data);
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

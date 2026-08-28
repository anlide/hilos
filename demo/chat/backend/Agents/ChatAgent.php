<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Agents\DTO\AccountMergeSummary;
use Demo\Chat\Agents\DTO\ImpersonateStopActionDTO;
use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\AccountMergeSignalData;
use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
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
use Hilos\Database\View\Item\Session;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;

/**
 * Monopolistic chat worker for chat events, users, runtime connections, WebSocket lifecycle, and bot messages.
 *
 * On start, registers chat database tables and runtime collections as truth sources.
 *
 * It no longer holds this project's sessions: they went into {@see SessionsLibraryAgent}
 * whole (HIL-710). What stayed is the half a project cannot give away - who is on the wire,
 * what that person is called, and the tab that has to be told - so the two speak in frames.
 * {@see HilosSignalConstants::HILOS_SESSION_STATE} arrives saying what a session has become
 * and is answered by {@see self::applySessionState()};
 * {@see HilosSignalConstants::HILOS_SESSION_REBIND} goes back whenever this project wants a
 * session to say something else, which here is only ever an impersonation or a merged
 * account's forced sign-out.
 */
final class ChatAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::CHAT;

    // The session frame is declared HERE and not in the library, which is what routes it to
    // this agent: a destination is taken from whoever names a signal, and the library naming
    // its own outgoing frame would send it back to itself.
    public const array AGENT_SIGNALS = [
        ChatSignalConstants::BOT_MESSAGE => BotMessageSignalData::class,
        ChatSignalConstants::ACCOUNT_MERGE_REQUEST => AccountMergeSignalData::class,
        HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class,
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

    // Signing out and dismissing an ack write a SESSION, so they left with it; what is left
    // here is the one control that judges a project field - the administrator behind a
    // takeover - and therefore cannot be anywhere else (HIL-710).
    public const array AGENT_ACTIONS = [
        ChatSignalConstants::IMPERSONATE_STOP => ImpersonateStopActionDTO::class,
    ];

    /**
     * Registers chat truth sources and records chat startup.
     *
     * The session set is not among them any more, nor are the two runtime lists of the
     * browsers parked on a confirmation code: they belong to {@see SessionsLibraryAgent},
     * which claims them in its own process (HIL-710). The connections stay, because who is
     * on the wire is this node's truth and the row carries chat's own fields.
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
     * CLI command wrapper over {@see self::startImpersonation()}: parses the token and target
     * from the command payload and runs the shared core, carrying the operator's correlation
     * id into the rebind frame.
     *
     * Only a REFUSAL is answered here, and that is the split doing its work (HIL-710): the
     * guards read chat's own admin flag, so this process is the one that knows they failed,
     * while what the session actually became is known only to the library that wrote it -
     * which answers the same operator itself. Answering "accepted" here instead would make a
     * mistyped token look like a success. The reply runs inside the caught path so a command
     * failure never reaches the worker loop, which catches neither HilosException nor its
     * database children.
     *
     * @param CommandRequestDTO $data Command request carrying the session token and target user id
     */
    private function handleImpersonateStart(CommandRequestDTO $data): void
    {
        $sessionToken = (string)$data->payload[ChatCommandConstants::FIELD_SESSION_TOKEN];
        $targetUserId = (int)($data->payload[ChatCommandConstants::FIELD_TARGET_USER_ID] ?? 0);

        try {
            $this->startImpersonation($sessionToken, $targetUserId, null, $data->correlationId);
        } catch (HilosException $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));
        }
    }

    /**
     * CLI command wrapper over {@see self::stopImpersonation()}: parses the token from the
     * command payload and runs the shared core, carrying the operator's correlation id into
     * the rebind frame.
     *
     * Answers a refusal only, for the reason {@see self::handleImpersonateStart()} gives; the
     * admin the session goes back to is read off the row by the library and reported from
     * there, so nothing has to be captured before the marker is cleared any more.
     *
     * @param CommandRequestDTO $data Command request carrying the session token
     */
    private function handleImpersonateStop(CommandRequestDTO $data): void
    {
        $sessionToken = (string)$data->payload[ChatCommandConstants::FIELD_SESSION_TOKEN];

        try {
            $this->stopImpersonation($sessionToken, null, $data->correlationId);
        } catch (HilosException $e) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $e->getMessage()));
        }
    }

    /**
     * Starts impersonation: an admin session assumes another user's identity.
     *
     * Shared core behind both the CLI command (HIL-166) and the admin users-table
     * page-action ({@see AdminUsersPage}). Guards, in order: the
     * session must exist; its current user must be an admin (an anonymous or
     * non-admin session is rejected — which also blocks a second start once
     * impersonating a non-admin target); it must not already be impersonating (no
     * nesting); the target must exist and differ from the admin.
     *
     * The guards stay in this project and the write no longer happens here (HIL-710): they
     * read chat's own admin flag, which no framework library can see, while the session is
     * the library's to write. So this ASKS, in one frame carrying the state the session must
     * reach whole - the target as its user, this administrator as the marker behind it. The
     * marker travels with the bind rather than being written first, because the library
     * writes it first on the far side, which is what the identity going out has to read.
     *
     * An audit line records the transition here, where the decision was made; what the
     * session actually became is recorded by the library that wrote it.
     *
     * @param string $sessionToken Session cookie token of the acting admin session
     * @param int $targetUserId User id to impersonate
     * @param ?string $initiatorAcceptKey Accept key of the admin's connection, or null for the CLI path
     * @param ?string $correlationId Command correlation id to answer the operator on, or null for the page path
     * @throws ValidationException When a guard rejects the request
     * @throws InvalidArgumentException When the rebind frame cannot be named
     * @throws HilosException On database or runtime failure
     */
    public function startImpersonation(
        string $sessionToken,
        int $targetUserId,
        ?string $initiatorAcceptKey,
        ?string $correlationId,
    ): void {
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

        $this->sendToAgent(HilosSignalConstants::HILOS_SESSION_REBIND, new SessionRebindSignalData(
            sessionToken: $sessionToken,
            userId: $targetUserId,
            impersonatorUserId: $adminId,
            initiatorAcceptKey: $initiatorAcceptKey,
            correlationId: $correlationId,
        ));

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
     * Shared core behind both the CLI command (HIL-166) and the shell agent-action. The
     * session must exist and must currently be impersonating; the admin to go back to is
     * read off the marker. The inverse of {@see self::startImpersonation()} and asked for the
     * same way - one frame naming the whole target state, here the administrator as the user
     * and no marker behind them.
     *
     * @param string $sessionToken Session cookie token of the impersonating session
     * @param ?string $initiatorAcceptKey Accept key of the requesting connection, or null for the CLI path
     * @param ?string $correlationId Command correlation id to answer the operator on, or null for the shell path
     * @throws ValidationException When the session is missing or not impersonating
     * @throws InvalidArgumentException When the rebind frame cannot be named
     * @throws HilosException On database or runtime failure
     */
    public function stopImpersonation(
        string $sessionToken,
        ?string $initiatorAcceptKey,
        ?string $correlationId,
    ): void {
        $session = Hilos::$db->sessions->findByToken($sessionToken);
        if ($session === null) {
            throw new ValidationException('No such session');
        }

        $impersonatorId = $session->impersonatorUserId;
        if ($impersonatorId === null) {
            throw new ValidationException('Session is not impersonating');
        }

        $this->sendToAgent(HilosSignalConstants::HILOS_SESSION_REBIND, new SessionRebindSignalData(
            sessionToken: $sessionToken,
            userId: $impersonatorId,
            impersonatorUserId: null,
            initiatorAcceptKey: $initiatorAcceptKey,
            correlationId: $correlationId,
        ));

        $this->logAgentInfo('impersonate_stop ' . json_encode([
            'event' => 'impersonate_stop',
            'admin' => $impersonatorId,
            'restoredUser' => $impersonatorId,
            // The identity being vacated, read before the frame lands and restores the admin.
            'vacatedUser' => $session->userId,
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
     * The post-commit force-logout of account merge: a tombstoned loser must not keep acting
     * through an open session. Each of the loser's sessions is ASKED to become anonymous, in
     * the same frame an impersonation is asked for (HIL-710) - the library unbinds the row
     * and says so back, and this agent re-points the live connections and clears their
     * frontends when that answer arrives. The loser is deactivated (`block = 1` from the
     * tombstone), so re-authentication is impossible.
     *
     * The impersonation marker is carried through unchanged rather than named null: the
     * frame states the target state whole, and a session someone was impersonating through
     * is being signed out, not un-impersonated. Runs outside the merge transaction: the
     * transfer is already durable, and nothing here may participate in the DB rollback path.
     *
     * @param int $loserId Merged loser user id whose sessions are closed
     * @throws InvalidArgumentException When a rebind frame cannot be named
     * @throws HilosException When reading the loser's sessions fails
     */
    private function killUserSessions(int $loserId): void
    {
        foreach (Hilos::$db->sessions->findByUserId($loserId) as $session) {
            $this->sendToAgent(HilosSignalConstants::HILOS_SESSION_REBIND, new SessionRebindSignalData(
                sessionToken: $session->token,
                userId: null,
                impersonatorUserId: $session->impersonatorUserId,
            ));
        }
    }

    /**
     * Says out loud what the sessions library concluded about one session (HIL-710).
     *
     * The project half of the seam, and the ONE handler behind every ending a session can
     * reach: a handshake, a sign-in, a sign-out, an impersonation, an ack raised or
     * dismissed. It replaces what used to be four hooks of the session-host trait
     * (`onSignalHandshake`, `bindConnectionUser`, `markConnectionAck` and the response signal
     * name), and it can be one handler precisely because the library states the session's
     * whole state rather than the change it made.
     *
     * THE ORDER IS THE MECHANISM, not a sequence of chores:
     * 1. the connection rows are written first, because everything below is read against
     *    them - the page re-decision judges "this connection belongs to N", and a browser
     *    told who it is before the row said so would be judged as the person it no longer is;
     * 2. each named socket is then handed the identity, stamped by the framework with the
     *    clock and the registration step the frame carried;
     * 3. a rotation ticket goes to the one socket that earned it, AFTER that identity, so the
     *    browser reconnects knowing who it already is;
     * 4. the pages are re-asked their access question once, not once per socket;
     * 5. the action that was waiting is answered LAST - behind the identity it announces,
     *    which is the rule the sign-in commands were split along (HIL-622).
     *
     * @param SessionStateSignalData $frame What the session is now, and whom to answer
     * @throws InvalidArgumentException When a signal of the answer cannot be named
     * @throws InvalidFormatException When the frame's outcome cannot be read back
     * @throws HilosException On database or runtime failure
     */
    private function applySessionState(SessionStateSignalData $frame): void
    {
        $userId = $frame->userId;
        if ($userId !== null) {
            Hilos::$rt->userStates->actions->ensure($userId);
        }

        // One identity for the whole frame: every socket it names belongs to the one session
        // it is about, so the user, the name and the administrator behind them are the same
        // for all of them. A session with nobody in it needs no lookup to be described.
        $identity = $this->handshakeResponseFor(
            $userId === null ? null : Hilos::$db->sessions->findByToken($frame->sessionToken),
        );

        foreach ($frame->acceptKeys as $acceptKey) {
            $this->settleConnection($acceptKey, $frame);
            $this->sendHandshakeResponse(ChatSignalConstants::HANDSHAKE_RESPONSE, $acceptKey, $identity, $frame);
        }

        $this->handOverRotationTicket($frame);

        // Whatever page these browsers had open was answered for whoever they were a moment
        // ago (HIL-652, HIL-627). A sign-in can ask by user, because the identity has just
        // appeared; a sign-out has to ask by connection, because the identity it would ask
        // under is the one being erased.
        if ($userId !== null) {
            PageAccessReassessment::forUser($userId);
        } else {
            PageAccessReassessment::forConnections($frame->acceptKeys);
        }

        $this->answerLibraryAction($frame);
    }

    /**
     * Brings one live connection row to the state the frame states.
     *
     * A socket the collection does not hold yet is a HANDSHAKE and nothing else: every other
     * frame names sockets the library read out of this very collection. So the row is opened
     * here, and the analytics identity is attached here, exactly where the handshake handler
     * used to do both.
     *
     * Each of the three writes happens only where it changes something. That is not thrift
     * but fidelity: a row written is a row synced to every reader of it, and a frame that
     * merely restates an unchanged session - an ack dismissed, an action answered - has no
     * business telling the rest of the node that the connections moved.
     *
     * @param string $acceptKey Accept key of the connection being brought up to date
     * @param SessionStateSignalData $frame What the session is now
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When the queued runtime-sync signal cannot be named
     */
    private function settleConnection(string $acceptKey, SessionStateSignalData $frame): void
    {
        $connection = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($connection === null) {
            Hilos::$rt->connections->actions->register($acceptKey, $frame->userId, $frame->sessionToken);
            if ($frame->userId !== null) {
                Hilos::$ac?->identifyBrowserSessionUser($frame->sessionToken, $frame->userId);
            }
        } else {
            if ($connection->sessionToken !== $frame->sessionToken) {
                // The session did not change, its secret name did: a login rotated the token
                // out from under a value somebody may have planted (HIL-582).
                Hilos::$rt->connections->actions->repointSessionToken($acceptKey, $frame->sessionToken);
            }
            if ($connection->userId !== $frame->userId) {
                $connection->actions->bindUser($frame->userId);
            }
        }

        // The frame STATES the ack rather than amending it, so a socket that owes nothing is
        // told so too; a socket born of a rotation inherits what the one it replaced had not
        // shown yet (HIL-423), and that arrives here as the ack of a row that does not exist.
        if ($connection?->pendingAck !== $frame->pendingAck) {
            Hilos::$rt->connections->actions->markAck($acceptKey, $frame->pendingAck);
        }
    }

    /**
     * Hands the browser that logged in the ticket it trades for its rotated cookie (HIL-582).
     *
     * Sent from this project rather than from the library that minted it, so that it leaves
     * behind the identity above: the browser reconnects the moment it holds the ticket, and a
     * ticket overtaking the response would drop the socket before it learned who it had
     * become. A frame carrying a ticket names exactly one socket - the one that logged in -
     * which is also the only rightful holder of a one-time value.
     *
     * @param SessionStateSignalData $frame Session state that may carry a rotation
     * @throws InvalidArgumentException When the rotation signal cannot be named
     */
    private function handOverRotationTicket(SessionStateSignalData $frame): void
    {
        $ticket = $frame->rotationTicket;
        $acceptKey = $frame->initiatorAcceptKey();
        if ($ticket === null || $acceptKey === null) {
            return;
        }

        $this->sendToUser(
            HilosSignalConstants::HILOS_SESSION_ROTATE,
            $acceptKey,
            new SessionRotateSignalData($ticket),
        );
    }

    /**
     * Answers the sign-in action the frame finished, when somebody is waiting on it.
     *
     * The library deferred its own ack so that the answer would leave from behind the
     * identity it announces (HIL-622), and since the split that identity is sent from here -
     * so the answer is sent from here too. Nothing is sent for a frame carrying no request
     * id: an OAuth login was acked as "accepted, working on it" the moment the browser was
     * sent off to the provider.
     *
     * @param SessionStateSignalData $frame Session state that may end a tracked action
     * @throws InvalidArgumentException When the reply signal cannot be named
     * @throws InvalidFormatException When the outcome the frame carries cannot be read back
     */
    private function answerLibraryAction(SessionStateSignalData $frame): void
    {
        $action = $frame->action;
        $requestId = $frame->requestId;
        $acceptKey = $frame->initiatorAcceptKey();
        if ($action === null || $requestId === null || $acceptKey === null) {
            return;
        }

        $outcome = $frame->outcome;
        $this->sendActionSuccess(
            $acceptKey,
            $action,
            $requestId,
            $outcome === null ? null : AuthFlowOutcome::fromArray($outcome),
        );
    }

    /**
     * Builds the identity half of a handshake response: who the session is, as this project
     * knows it, with the impersonatedBy slot filled from the session's impersonator marker.
     *
     * The whole of what stayed behind when the sessions left (HIL-710) - it reads the display
     * names from the chat user store and, while impersonating, the admin behind the takeover.
     * An anonymous or missing session yields the anonymous response that clears the frontend
     * current user; an impersonated session additionally carries the impersonating admin, so
     * the shell shows its banner, while a plain authenticated session leaves the impersonator
     * fields null. The clock and the unfinished registration step are NOT filled here: the
     * framework stamps them on the way out, from the frame the library sent.
     *
     * @param ?Session $session Session to describe, or null for an anonymous response
     * @return HandshakeResponseSignalData Identity of the session, unstamped
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
     * The state it sends under is assembled here rather than received, because this is the
     * one send path that answers no frame: nobody's session changed, only the flag on their
     * account did. Two of its fields therefore have to be said deliberately. The pending ack
     * is carried over from the row for the reason every other re-send carries it (HIL-422) -
     * the response states the ack rather than amending it, so an admin flip that went out
     * without one would wipe an announcement the person has not read yet. The unfinished
     * registration is null, and truthfully so: this reaches a signed-in person, and signing
     * in is what releases the step.
     *
     * @param int $userId User whose connections are told
     * @throws InvalidArgumentException When the response signal cannot be named
     * @throws HilosException When a session or user lookup fails
     */
    private function broadcastHandshakeResponseToUser(int $userId): void
    {
        foreach (Hilos::$rt->connections->forUser($userId) as $connection) {
            $sessionToken = $connection->sessionToken;
            if ($sessionToken === null) {
                continue;
            }

            $session = Hilos::$db->sessions->findByToken($sessionToken);
            $this->sendHandshakeResponse(
                ChatSignalConstants::HANDSHAKE_RESPONSE,
                $connection->acceptKey,
                $this->handshakeResponseFor($session),
                new SessionStateSignalData(
                    sessionToken: $sessionToken,
                    userId: $userId,
                    acceptKeys: [$connection->acceptKey],
                    pendingAck: $connection->pendingAck,
                ),
            );
        }
    }

    /**
     * Routes the one agent-owned client action left here (HIL-710).
     *
     * Impersonation-stop is page-independent - its control lives in the app shell, and while
     * impersonating the effective user is the non-admin target, so no admin page is
     * guaranteed - which is why it arrives here rather than through a page. Signing out and
     * dismissing an ack arrived here for the same reason and have moved to the sessions
     * library, because what they write is a session.
     *
     * @param string $acceptKey Acting connection accept key
     * @param string $action Owned action name from AGENT_ACTIONS
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: it answers with no domain data
     * @throws AgentUnknownActionException When action is not supported by this agent
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ValidationException When impersonation-stop is invoked on a non-impersonating session
     * @throws InvalidArgumentException When the rebind frame cannot be named
     * @throws HilosException When impersonation-stop exposes database or runtime failure
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
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
     * @throws InvalidArgumentException When the rebind frame cannot be named
     * @throws HilosException When impersonation teardown exposes database or runtime failure
     */
    private function handleImpersonateStopAction(string $acceptKey): void
    {
        $sessionToken = Hilos::$rt->connections[$acceptKey]?->sessionToken;
        if ($sessionToken === null || $sessionToken === '') {
            return;
        }

        // No correlation id: this came from a browser, and what it gets back is the identity
        // frame the rebind ends in, not a command reply.
        $this->stopImpersonation($sessionToken, $acceptKey, null);
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
     * Handles chat-owned cron cleanup for persisted history and transient attachment state.
     *
     * The expired-registration-hold sweep used to be here too. It went with the sessions
     * (HIL-710): what it frees is a hold on a session row, and it is scheduled by
     * {@see SessionsLibraryAgent} on a rule of its own rather than by this demo's daemon,
     * which has one cron addressee for every name it schedules.
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
     * @throws InvalidArgumentException When a signal of the session-state answer cannot be named
     * @throws InvalidFormatException When a session-state outcome cannot be read back
     * @throws HilosException On bot message publish failure, or on database or runtime failure
     * @throws LogicException On payload type mismatch, or if event id is null after sync
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_SESSION_STATE:
                if (!$data->data instanceof SessionStateSignalData) {
                    throw new LogicException(
                        HilosSignalConstants::HILOS_SESSION_STATE . ' payload must be '
                        . SessionStateSignalData::class,
                    );
                }
                $this->applySessionState($data->data);
                return;
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

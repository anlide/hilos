<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ActionFailSignalData;
use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\View\Item\Session;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Users\DTO\AccountMergeResultSignalData;

/**
 * Monopolistic chat worker for chat events, users, runtime connections, WebSocket lifecycle, and bot messages.
 *
 * On start, registers chat database tables and runtime collections as truth sources.
 *
 * It no longer holds this project's sessions: they went into {@see SessionsLibraryAgent}
 * whole (HIL-710). What stayed is the half a project cannot give away - who is on the wire,
 * what that person is called, and the tab that has to be told - so the two speak in frames.
 * {@see HilosSignalConstants::HILOS_SESSION_STATE} arrives saying what a session has become
 * and is answered by {@see self::applySessionState()}.
 *
 * Since HIL-729 it asks the library for nothing at all: impersonation and account merge both
 * moved there whole, because the guards and the write they need are the same process again,
 * and what this project answers is a seam apiece. The one frame it still receives back is
 * {@see HilosSignalConstants::HILOS_ACCOUNT_MERGE_RESULT} - the merge is asked for on a page
 * of this project's, and only this project knows the ack name that page listens under.
 */
final class ChatAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::CHAT;

    // Both library frames are declared HERE and not in the library, which is what routes them
    // to this agent: a destination is taken from whoever names a signal, and the library
    // naming its own outgoing frame would send it back to itself. One says what a session has
    // become, the other what a merge this project asked for did (HIL-710, HIL-729).
    public const array AGENT_SIGNALS = [
        ChatSignalConstants::BOT_MESSAGE => BotMessageSignalData::class,
        HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class,
        HilosSignalConstants::HILOS_ACCOUNT_MERGE_RESULT => AccountMergeResultSignalData::class,
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
     * Each write happens only where it changes something. That is not thrift but fidelity: a
     * row written is a row synced to every reader of it, and a frame that merely restates an
     * unchanged session - an ack dismissed, an action answered - has no business telling the
     * rest of the node that the connections moved.
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
     * Dispatches chat-owned inter-agent signals and deliberately ignores the page-owned ones.
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
            case ChatSignalConstants::USER_ADMIN_RENAME_DONE:
                // Both belong to a page this agent merely serves: the frame is routed here
                // because that is where the page lives, and the page router hands it on. Named
                // rather than left to the default, which would log a stranger on every one.
                return;
            case ChatSignalConstants::BOT_MESSAGE:
                if (!$data->data instanceof BotMessageSignalData) {
                    throw new LogicException(
                        ChatSignalConstants::BOT_MESSAGE . ' payload must be ' . BotMessageSignalData::class,
                    );
                }
                $this->handleBotMessage($data->data);
                return;
            case HilosSignalConstants::HILOS_ACCOUNT_MERGE_RESULT:
                if (!$data->data instanceof AccountMergeResultSignalData) {
                    throw new LogicException(
                        HilosSignalConstants::HILOS_ACCOUNT_MERGE_RESULT . ' payload must be '
                        . AccountMergeResultSignalData::class,
                    );
                }
                $this->ackAccountMerge($data->data);
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
     * Tells the admin who asked for a merge what became of it (HIL-378, HIL-729).
     *
     * The project half of the merge seam, and the reason the frame exists at all: the work
     * runs in {@see SessionsLibraryAgent} - it ends in the loser's sessions being signed out,
     * and those are the library's - while the name the browser is listening under is chat's
     * own. So the library hands back the outcome and the accept key it was given, and this
     * turns them into the one-to-one ack the admin page waits for.
     *
     * The frame carries one outcome of two types, so the branch below is total: a sentence is
     * a refusal, anything else is what moved.
     *
     * @param AccountMergeResultSignalData $result What the merge moved, or why it moved nothing
     * @throws InvalidArgumentException When the ack cannot be named
     * @throws HilosException On runtime failure while sending the ack
     */
    private function ackAccountMerge(AccountMergeResultSignalData $result): void
    {
        $outcome = $result->outcome;
        if (is_string($outcome)) {
            $this->sendToUser(
                ChatSignalConstants::ACCOUNT_MERGE_FAIL,
                $result->acceptKey,
                new ActionFailSignalData($outcome),
            );

            return;
        }

        $this->sendToUser(
            ChatSignalConstants::ACCOUNT_MERGE_SUCCESS,
            $result->acceptKey,
            new ActionSuccessSignalData($outcome->toArray()),
        );
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

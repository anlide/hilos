<?php

declare(strict_types=1);

namespace Demo\Tasks\Agents;

use Demo\Tasks\Agents\Hilos\SessionsLibraryAgent;
use Demo\Tasks\Constants\AgentType;
use Demo\Tasks\Constants\TasksSignalConstants;
use Demo\Tasks\Core\Router\DTO\GuestIdentitySignalData;
use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Hilos;
use Demo\Tasks\Runtime\View\Context\TasksRtContext;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\View\Item\Session;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;

/**
 * Monopolistic tasks worker that owns the main page subscription and the
 * WebSocket lifecycle signals.
 *
 * Session identity is the framework's, and since HIL-710 it is a library's too: the cookie
 * is resolved by {@see SessionsLibraryAgent}, which says what the session is in one frame,
 * and this agent tracks the socket as a runtime connection of that session for presence.
 * This demo DOES carry anonymous sessions (HIL-610): a `user` row means an account, minted
 * only by {@see CliCommands::ADMIN_CREATE} - which moved to the library with the session
 * bind it ends in - and a visitor without one is remembered by name alone in this demo's own
 * `guest` table.
 */
final class TasksAgent extends AbstractAgent
{
    /** @var list<string> The guest rows behind the people it serves, owned by the users library */
    public const array READS_DB = [TasksDbContext::guests];

    public const string AGENT_TYPE = AgentType::TASKS;

    // The session frame is declared HERE and not in the library, which is what routes it to
    // this agent: a destination is taken from whoever names a signal, and the library naming
    // its own outgoing frame would send it back to itself.
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class,
    ];

    /**
     * Registers the user table and the connections runtime collection as this
     * worker's truth sources so their changes fan out to the browser.
     */
    public function onStart(): void
    {
        $this->registerDbTruthSource(TasksDbContext::users);
        $this->registerRtTruthSource(TasksRtContext::connections);
    }

    /**
     * Says out loud what the sessions library concluded about one session (HIL-710).
     *
     * The project half of the seam, and the ONE handler behind every ending a session can
     * reach here: a handshake, and the operator's admin:create binding an account to a live
     * browser. It replaces what used to be four hooks of the session-host trait
     * (`onSignalHandshake`, `bindConnectionUser`, `markConnectionAck` and the response signal
     * name), and it can be one handler precisely because the library states the session's
     * whole state rather than the change it made.
     *
     * THE ORDER IS THE MECHANISM: the connection rows are written first, so that everything
     * read against them - the page re-decision above all - sees who the socket belongs to
     * now; the guest name goes out BEFORE the identity, so the line on the page is never
     * drawn empty (HIL-610); the identity follows, stamped by the framework with the server
     * clock; and the action that was waiting is answered last, behind the identity it
     * announces (HIL-622).
     *
     * @param SessionStateSignalData $frame What the session is now, and whom to answer
     * @throws InvalidArgumentException When a signal of the answer cannot be named
     * @throws InvalidFormatException When the frame's outcome cannot be read back
     * @throws HilosException On database or runtime failure
     */
    private function applySessionState(SessionStateSignalData $frame): void
    {
        $userId = $frame->userId;
        // One identity for the whole frame: every socket it names belongs to the one session
        // it is about. A session with nobody in it needs no lookup to be described.
        $identity = $this->handshakeResponseFor(
            $userId === null ? null : Hilos::$db->sessions->findByToken($frame->sessionToken),
        );

        foreach ($frame->acceptKeys as $acceptKey) {
            $this->settleConnection($acceptKey, $frame);
            $this->nameTheVisitor($acceptKey, $frame);
            $this->sendHandshakeResponse(TasksSignalConstants::HANDSHAKE_RESPONSE, $acceptKey, $identity, $frame);
        }

        $this->handOverRotationTicket($frame);

        // Whatever page these browsers had open was answered for whoever they were a moment
        // ago (HIL-652). A bind can ask by user, because the identity has just appeared; a
        // sign-out has to ask by connection, because the identity it would ask under is the
        // one being erased.
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
     * frame names sockets the library read out of this very collection. Each write happens
     * only where it changes something - a row written is a row synced to every reader of it,
     * and a frame that merely restates an unchanged session has no business telling the rest
     * of the node that the connections moved.
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
        } else {
            if ($connection->sessionToken !== $frame->sessionToken) {
                Hilos::$rt->connections->actions->repointSessionToken($acceptKey, $frame->sessionToken);
            }
            if ($connection->userId !== $frame->userId) {
                $connection->actions->bindUser($frame->userId);
            }
        }

        if ($connection?->pendingAck !== $frame->pendingAck) {
            Hilos::$rt->connections->actions->markAck($acceptKey, $frame->pendingAck);
        }
    }

    /**
     * Gives an anonymous browser a name of its own, or takes back the one it outgrew (HIL-610).
     *
     * Sent BEFORE the identity response, so the line on the page is never drawn empty: a
     * visitor is the normal state of this demo, not a moment to be closed. A session that
     * does carry an account has its guest row dropped on the way past - that is how the row
     * minted before an operator claimed this browser goes, and doing it on the path that is
     * certain to run keeps the cleanup out of the command.
     *
     * @param string $acceptKey Accept key of the connection being told
     * @param SessionStateSignalData $frame What the session is now
     * @throws HilosException On database failure while naming or dropping the guest
     * @throws InvalidArgumentException When the guest-identity signal cannot be named
     */
    private function nameTheVisitor(string $acceptKey, SessionStateSignalData $frame): void
    {
        if ($frame->userId !== null) {
            Hilos::$db->guests->actions->deleteForSession($frame->sessionToken);

            return;
        }

        $guest = Hilos::$db->guests->actions->ensureForSession($frame->sessionToken);
        $this->sendToUser(
            TasksSignalConstants::GUEST_IDENTITY,
            $acceptKey,
            new GuestIdentitySignalData($guest->name),
        );
    }

    /**
     * Hands the browser that just signed in the ticket it trades for its rotated cookie
     * (HIL-582).
     *
     * Nothing in this demo rotates a session today - the one bind it has comes from an
     * operator's command, which names no initiating connection - but the frame is the
     * framework's and may carry one, and a ticket dropped on the floor would cost the person
     * their session. Sent from here so that it leaves behind the identity above.
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
     * Answers the action the frame finished, when somebody is waiting on it.
     *
     * The library deferred its own ack so that the answer would leave from behind the
     * identity it announces (HIL-622), and since the split that identity is sent from here -
     * so the answer is sent from here too.
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
     * Routes the one frame this agent is addressed by.
     *
     * @param AgentSignalData $data Agent signal wrapper with the inner payload to dispatch
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this agent
     * @throws InvalidArgumentException When a signal of the answer cannot be named
     * @throws InvalidFormatException When a session-state outcome cannot be read back
     * @throws LogicException On payload type mismatch
     * @throws HilosException On database or runtime failure
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        if ($name !== HilosSignalConstants::HILOS_SESSION_STATE) {
            throw new AgentUnknownSignalException($name);
        }

        if (!$data->data instanceof SessionStateSignalData) {
            throw new LogicException(
                HilosSignalConstants::HILOS_SESSION_STATE . ' payload must be ' . SessionStateSignalData::class,
            );
        }

        $this->applySessionState($data->data);
    }

    /**
     * Builds the identity half of a handshake response, reading the display name from this
     * demo's own user table - the whole of what stayed behind when the sessions left
     * (HIL-710). The clock is NOT filled here: the framework stamps it on the way out.
     *
     * The impersonator slots stay null: this demo has no impersonation, so the
     * only identity a session can carry is its own. A session with no user - the
     * ordinary state of a visitor since HIL-610 - yields the anonymous response,
     * which leaves the frontend without a current user; the guest name it shows
     * instead travels on its own signal and is not an identity.
     *
     * @param ?Session $session Session to describe, or null for an anonymous response
     * @return HandshakeResponseSignalData Handshake response for the session
     * @throws HilosException When the user lookup fails
     */
    private function handshakeResponseFor(?Session $session): HandshakeResponseSignalData
    {
        $userId = $session?->userId;
        if ($userId === null) {
            return new HandshakeResponseSignalData();
        }

        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            return new HandshakeResponseSignalData();
        }

        return new HandshakeResponseSignalData(
            selfId: (int)$user->id,
            selfName: $user->name,
            selfAdmin: $user->admin,
        );
    }

    /**
     * Unregisters the closed WebSocket connection from runtime presence.
     *
     * @param WebSocketCloseSignalDTO $data Closed WebSocket connection
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException On runtime cleanup failure
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        Hilos::$rt->connections[$data->acceptKey]?->actions->unregister();
    }

    /**
     * Nothing to clean up on shutdown: this agent owns no runtime state of its own.
     *
     * It used to empty the connections here, and that was the defect (HIL-664): who is on the
     * wire is the truth of the node holding the sockets, not of the agent, and a stop closes no
     * socket. The rows outlive the agent now, and the sockets that died while it was down are
     * struck against the master's roster when it comes back.
     */
    public function onStop(): void
    {
    }

}

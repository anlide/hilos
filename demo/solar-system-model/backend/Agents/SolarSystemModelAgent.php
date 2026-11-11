<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Agents;

use Demo\SolarSystemModel\Constants\AgentType;
use Demo\SolarSystemModel\Constants\SolarSystemModelSignalConstants;
use Demo\SolarSystemModel\Hilos;
use Demo\SolarSystemModel\Runtime\View\Context\SolarSystemModelRtContext;
use Hilos\Auth\Session\DTO\SessionRotateSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use LogicException as NativeLogicException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\RelayedActionReplyDTO;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;

/**
 * Owns Solar System Model's connections and applies the framework session-state frames.
 *
 * Connection writes precede identity delivery, page reassessment and the deferred action reply.
 */
final class SolarSystemModelAgent extends AbstractAgent
{
    /** @var array<string, list<TruthSourceOperation>> Who is on the wire */
    public const array OWNS_RT = [SolarSystemModelRtContext::connections => TruthSourceOperation::BY_KIND];

    public const string AGENT_TYPE = AgentType::SOLAR_SYSTEM_MODEL;

    // The session frame is declared HERE and not in the library, which is what routes it to
    // this agent: a destination is taken from whoever names a signal, and the library naming
    // its own outgoing frame would send it back to itself. The sweep frame is not declared:
    // nothing of this demo's own hangs on a session row, and a frame nobody routes is one the
    // library does not send.
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class,
    ];

    /**
     * Routes the session state frame this agent owns.
     *
     * @param AgentSignalData $data Agent signal wrapper with the inner payload to dispatch
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Agent signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this agent
     * @throws InvalidArgumentException When a signal of the answer cannot be named
     * @throws LogicException On payload type mismatch
     * @throws NativeLogicException When the runtime item factory rejects a connection
     * @throws HilosException On database or runtime failure
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_SESSION_STATE:
                if (!$data->data instanceof SessionStateSignalData) {
                    throw new LogicException(
                        HilosSignalConstants::HILOS_SESSION_STATE . ' payload must be ' . SessionStateSignalData::class,
                    );
                }

                $this->applySessionState($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
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

    /** Connection rows survive agent restarts; the framework reconciles them with live sockets. */
    public function onStop(): void
    {
    }

    /**
     * Applies a session frame before reassessing its browsers and answering its action.
     *
     * @param SessionStateSignalData $frame What the session is now, and whom to answer
     * @throws InvalidArgumentException When a signal of the answer cannot be named
     * @throws HilosException On database or runtime failure
     * @throws NativeLogicException When the runtime item factory rejects a connection
     */
    private function applySessionState(SessionStateSignalData $frame): void
    {
        $acceptKeys = Hilos::$rt->connections->acceptKeysForSessionFrame($frame->acceptKeys, $frame->sessionToken);
        if ($acceptKeys === []) {
            return;
        }

        $userId = $frame->userId;
        // One identity for the whole frame: every socket it reaches belongs to the one session
        // it is about - the ones it names and the rest of the session's, which only this
        // register knows of yet.
        $identity = $this->handshakeIdentity($frame);

        foreach ($acceptKeys as $acceptKey) {
            $this->settleConnection($acceptKey, $frame);
            $this->sendHandshakeResponse(SolarSystemModelSignalConstants::HANDSHAKE_RESPONSE, $acceptKey, $identity, $frame);
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
     * Registers or updates the live connection only when the session frame changes it.
     *
     * @param string $acceptKey Accept key of the connection being brought up to date
     * @param SessionStateSignalData $frame What the session is now
     * @throws HilosException On runtime failure
     * @throws InvalidArgumentException When the queued runtime-sync signal cannot be named
     * @throws NativeLogicException When the runtime item factory rejects a connection
     */
    private function settleConnection(string $acceptKey, SessionStateSignalData $frame): void
    {
        $connection = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($connection === null) {
            Hilos::$rt->connections->actions->register(
                $acceptKey,
                $frame->userId,
                $frame->sessionToken,
                $frame->sessionId,
            );
        } else {
            if ($connection->sessionToken !== $frame->sessionToken || $connection->sessionId !== $frame->sessionId) {
                Hilos::$rt->connections->actions->repointSessionToken(
                    $acceptKey,
                    $frame->sessionToken,
                    $frame->sessionId,
                );
            }
            if ($connection->userId !== $frame->userId) {
                $connection->actions->bindUser($frame->userId);
            }
        }
    }

    /**
     * Sends the rotated-cookie ticket after identity delivery.
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
     * Sends the library action reply after the identity it announces.
     *
     * @param SessionStateSignalData $frame Session state that may end a tracked action
     * @throws InvalidArgumentException When the reply signal cannot be named
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
            $outcome === null ? null : new RelayedActionReplyDTO($outcome),
        );
    }
}

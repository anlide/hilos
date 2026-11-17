<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Generator;
use Hilos\Cluster\AgentSignalMesh;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Destination\AgentAddressedDestination;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\CommandReplyDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;
use Throwable;

/** Typed master operations needed by retained signal scheduling. */
interface SignalDispatchHost
{
    /**
     * Release upload accounting after the guarded portion, including on failure.
     *
     * @throws HilosException When the host cannot complete dispatch accounting
     */
    public function afterSignalDispatch(): void;

    /**
     * @param SignalDispatch $dispatch Original receipt and resolved page context
     * @return bool Whether the master's receipt and page guards still permit this lifetime
     */
    public function mayContinueSignalDispatch(SignalDispatch $dispatch): bool;

    /**
     * @param SignalDispatch $dispatch Original lifetime
     * @param bool $wasParked Whether identity admission already completed
     * @param WorkerServer $workerServer Worker transport
     * @param ?WebSocketServer $webSocketServer Browser transport
     * @param ?PeerServer $peerServer Peer transport
     * @return Generator<int, void, void, SignalDispatchPhase> Preparation or an early terminal outcome
     * @throws Throwable When subscription preparation or shared apply fails
     */
    public function prepareSignalDispatch(
        SignalDispatch $dispatch, bool $wasParked, WorkerServer $workerServer,
        ?WebSocketServer $webSocketServer, ?PeerServer $peerServer,
    ): Generator;

    /**
     * @param SignalDispatch $dispatch Original prepared lifetime
     * @throws EventLoopException When readiness or cron cannot be cued
     */
    public function beginSignalDestinations(SignalDispatch $dispatch): void;

    /**
     * @param SignalDispatch $dispatch Original routed lifetime
     * @param bool $hasDestination Whether the router produced a target
     * @throws InvalidArgumentException When an unserved refusal cannot be named
     * @throws InvalidFormatException When subscription recovery configuration is invalid
     */
    public function finishUnservedSignal(SignalDispatch $dispatch, bool $hasDestination): void;

    /**
     * @param SignalDispatch $dispatch Original lifetime and addressed agents
     * @param WorkerServer $workerServer Worker transport
     * @return Generator<int, void> Existing connection-close and subscription cleanup
     * @throws Throwable When final delivery fails
     */
    public function finishSignalDispatch(SignalDispatch $dispatch, WorkerServer $workerServer): Generator;

    /**
     * @param SignalDispatch $dispatch Original reply lifetime
     * @param CommandReplyDestination $destination Original correlation
     * @param ?CommandServer $commandServer Local command transport
     * @param ?PeerServer $peerServer Peer transport
     * @return Generator<int, void> Original waiter settlement or bounded peer delivery
     * @throws EventLoopException When a reply deadline cannot be canceled
     */
    public function deliverSignalCommandReply(
        SignalDispatch $dispatch, CommandReplyDestination $destination, ?CommandServer $commandServer, ?PeerServer $peerServer,
    ): Generator;

    /**
     * @param SignalDispatch $dispatch Exact signal lifetime
     * @param ?WebSocketServer $webSocketServer Owning local browser server
     * @return Generator<int, void> One bounded lookup, cached only for this signal
     */
    public function resolveSignalClient(SignalDispatch $dispatch, ?WebSocketServer $webSocketServer): Generator;

    /**
     * @param SignalDispatch $dispatch Original signal lifetime
     * @param WebSocketServer $server Exact owning browser server
     * @param Destination $destination One local browser address or fan-out marker
     * @return Generator<int, void> Shared frame preparation and bounded physical delivery
     * @throws InvalidFormatException When a matching completion reads an invalid delivery window
     */
    public function deliverSignalClient(SignalDispatch $dispatch, WebSocketServer $server, Destination $destination): Generator;

    /**
     * The session answer releases the browser's first page subscribe. Earlier session
     * notifications may already have opened cadence, but this reply must not wait for it.
     * The socket's whole-subscription hold still takes precedence over urgency.
     *
     * @param SignalDTO $signal Signal whose encoded text is shared by every local recipient
     * @return ?DeliveryFrame Immutable prepared text, absent when the encoder refused it
     */
    public function prepareSignalFrame(SignalDTO $signal): ?DeliveryFrame;

    /**
     * @param SignalDispatch $dispatch Original one-agent release
     * @return Generator<int, void, void, SignalDispatchPhase> Lookup and original page guard
     */
    public function prepareReleasedSignalDispatch(SignalDispatch $dispatch): Generator;

    /**
     * @param SignalDispatch $dispatch Original delivery lifetime
     * @param ?BrowserDeliveryTarget $target Captured socket incarnation
     * @param DeliveryFrame $frame Shared encoded frame
     * @param ?WebSocketDestination $destination Original subscription guard
     * @return Generator<int, void> Bounded physical send
     */
    public function deliverSignalTarget(
        SignalDispatch $dispatch, ?BrowserDeliveryTarget $target, DeliveryFrame $frame, ?WebSocketDestination $destination,
    ): Generator;

    /**
     * @param SignalDispatch $dispatch Original delivery lifetime
     * @param WorkerServer $workers Worker transport
     * @param ?AgentSignalMesh $mesh Peer transport
     * @param AgentAddressedDestination $destination Placed agent address
     * @param SignalDTO $signal Original envelope
     * @return AgentDeliveryOutcome Existing delivery verdict
     * @throws Throwable When the delivery hook or group authority cleanup fails
     */
    public function deliverSignalAgent(
        SignalDispatch $dispatch, WorkerServer $workers, ?AgentSignalMesh $mesh,
        AgentAddressedDestination $destination, SignalDTO $signal,
    ): AgentDeliveryOutcome;

    /**
     * @param SignalDispatch $dispatch Original forwarded lifetime
     * @param WorkerServer $workers Worker transport
     * @param AgentDestination $destination Local agent address
     * @param SignalDTO $signal Original envelope
     * @return AgentDeliveryOutcome Local-only delivery verdict
     * @throws InvalidArgumentException When a local refusal cannot be named
     */
    public function deliverLocalSignalAgent(
        SignalDispatch $dispatch, WorkerServer $workers, AgentDestination $destination, SignalDTO $signal,
    ): AgentDeliveryOutcome;

    /**
     * @param SignalDispatch $dispatch Original attempted lifetime
     * @param SignalDTO $signal Original envelope
     * @param AgentDeliveryOutcome $outcome Verdict to answer
     * @throws InvalidArgumentException When a refusal cannot be named
     * @throws InvalidFormatException When a subscription refusal reads invalid recovery configuration
     */
    public function answerSignalAgentOutcome(SignalDispatch $dispatch, SignalDTO $signal, AgentDeliveryOutcome $outcome): void;

    /**
     * @param ?SignalDispatch $origin Original asking lifetime; null is a root
     * @param string $agentType Addressed type
     * @param ?string $agentIndex Addressed instance
     * @throws EnvException When cluster configuration is invalid
     * @throws EventLoopException When the placement retry deadline cannot be armed
     */
    public function requireSignalPlacement(?SignalDispatch $origin, string $agentType, ?string $agentIndex): void;

    /** Cues independent leader-page recovery when its router basis changes. */
    public function noteSignalRouterChanged(): void;

    /**
     * @param WorkerServer $workerServer Local start transport
     * @param string $agentId Address whose unreported start is being checked
     * @return bool Whether the start is currently in progress on this node
     */
    public function isAgentStartUnderWay(WorkerServer $workerServer, string $agentId): bool;

    /**
     * @param string $agentId Address whose start report is being checked
     * @return bool Whether the master has received the agent's start report
     */
    public function isSignalAgentStarted(string $agentId): bool;

    /** @param ?string $agentId Changed worker address, absent for a resume completion */
    public function noteSignalWorkerFact(?string $agentId): void;

    /** @param ?string $agentId Changed placement address, absent for a new authority basis */
    public function noteSignalPlacementFact(?string $agentId): void;

    /** @return bool Whether the current lifecycle still accepts source delivery */
    public function acceptsSignalWork(): bool;
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\DTO\DbReHydrateOutcome;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;

/**
 * AgentInterface - Interface for agents running in worker processes.
 *
 * Agents are entities that perform work in worker processes.
 * They have a tick() method called regularly to perform their work.
 * Agents are identified by type + index combination.
 */
interface AgentInterface
{
    /**
     * Get agent type (for routing purposes)
     *
     * @return string Agent type (e.g., 'chat', 'user')
     */
    public function getType(): string;

    /**
     * Get agent index
     *
     * @return ?string Agent index (null if no index needed)
     */
    public function getIndex(): ?string;

    /**
     * Get agent unique identifier (type + index)
     *
     * @return string Agent ID in format "type:index" or "type" if index is null
     */
    public function getId(): string;

    /**
     * Tick method - called regularly in worker loop
     *
     * Performs agent's work on each tick. Called approximately every 100ms.
     *
     * @throws HilosException Whatever the concrete agent's tick raises
     */
    public function onTick(): void;

    /**
     * Called when agent is started
     *
     * Called once when agent is created and started.
     *
     * @throws HilosException Whatever the concrete agent's start raises
     */
    public function onStart(): void;

    /**
     * Called when agent is stopped
     *
     * Called once when agent is being destroyed/stopped.
     *
     * @throws HilosException Whatever the concrete agent's stop raises
     * @throws InvalidArgumentException Whatever the concrete agent's stop raises from SPL
     */
    public function onStop(): void;

    /**
     * Called on the initiator agent once the cluster has quiesced for its protected operation.
     *
     * Delivered on the initiator node after {@see AbstractAgent::requestProtectedModeEnable()} and
     * every node has frozen: the initiator may now run its destructive operation. Never called on a
     * non-initiator agent.
     *
     * @throws HilosException Whatever the concrete agent's protected operation raises
     * @throws InvalidArgumentException Whatever the concrete agent's protected operation raises from SPL
     */
    public function onProtectedModeReady(): void;

    /**
     * Called on the announcing agent once the node has finished re-reading a replaced database.
     *
     * Delivered on the announcing node after {@see AbstractAgent::requestDbReHydrate()} and every
     * process it reached has answered - or stopped answering. Never called on an agent that did
     * not announce a swap.
     *
     * @param DbReHydrateOutcome $outcome Whether the barrier closed, and who is missing from it
     * @throws HilosException Whatever the concrete agent finishes the swap with
     * @throws InvalidArgumentException Whatever the concrete agent finishes the swap with from SPL
     */
    public function onDbReHydrateComplete(DbReHydrateOutcome $outcome): void;

    /**
     * Handle system signal.
     *
     * @param SignalDataInterface $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's system-signal handler raises
     */
    public function onSignalSystem(SignalDataInterface $data, string $source, string $name): void;

    /**
     * Handle handshake signal.
     *
     * @param WebSocketHandshakeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's handshake handler raises, a payload that fails validation among them
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle connection close signal (WebSocket connection closed).
     *
     * @param WebSocketCloseSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's connection-close handler raises
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page subscribe signal.
     *
     * @param WebSocketPageSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's page-subscribe handler raises
     */
    public function onSignalPageSubscribe(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page unsubscribe signal.
     *
     * @param WebSocketPageUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's page-unsubscribe handler raises
     */
    public function onSignalPageUnsubscribe(WebSocketPageUnsubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page update subscription signal.
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's page-update-subscription handler raises
     */
    public function onSignalPageUpdateSubscription(WebSocketPageUpdateSubscriptionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group subscribe signal.
     *
     * @param WebSocketGroupSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's group-subscribe handler raises
     */
    public function onSignalGroupSubscribe(WebSocketGroupSubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group unsubscribe signal.
     *
     * @param WebSocketGroupUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's group-unsubscribe handler raises
     */
    public function onSignalGroupUnsubscribe(WebSocketGroupUnsubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group update subscription signal.
     *
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's group-update-subscription handler raises
     */
    public function onSignalGroupUpdateSubscription(WebSocketGroupUpdateSubscriptionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle action signal.
     *
     * @param WebSocketActionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's action handler raises
     */
    public function onSignalAction(WebSocketActionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle binary frame signal.
     *
     * @param WebSocketFrameBinarySignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's binary-frame handler raises
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void;

    /**
     * Handle cron signal.
     *
     * @param SignalDataInterface $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's cron handler raises
     * @throws InvalidArgumentException Whatever the concrete agent's cron handler raises from SPL
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void;

    /**
     * Handle a CLI command signal routed to this agent.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's command handler raises
     * @throws InvalidArgumentException When the handler cannot name its reply to the command
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void;
}

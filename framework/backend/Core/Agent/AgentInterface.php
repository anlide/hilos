<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\SignalDataInterface;
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
     */
    public function onTick(): void;

    /**
     * Called when agent is started
     *
     * Called once when agent is created and started.
     */
    public function onStart(): void;

    /**
     * Called when agent is stopped
     *
     * Called once when agent is being destroyed/stopped.
     */
    public function onStop(): void;

    /**
     * Handle system signal.
     *
     * @param SignalDataInterface $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalSystem(SignalDataInterface $data, string $source, string $name): void;

    /**
     * Handle handshake signal.
     *
     * @param WebSocketHandshakeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws ValidationException When the handshake payload fails validation
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle connection close signal (WebSocket connection closed).
     *
     * @param WebSocketCloseSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page subscribe signal.
     *
     * @param WebSocketPageSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalPageSubscribe(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page unsubscribe signal.
     *
     * @param WebSocketPageUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalPageUnsubscribe(WebSocketPageUnsubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page update subscription signal.
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalPageUpdateSubscription(WebSocketPageUpdateSubscriptionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group subscribe signal.
     *
     * @param WebSocketGroupSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalGroupSubscribe(WebSocketGroupSubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group unsubscribe signal.
     *
     * @param WebSocketGroupUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalGroupUnsubscribe(WebSocketGroupUnsubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group update subscription signal.
     *
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalGroupUpdateSubscription(WebSocketGroupUpdateSubscriptionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle action signal.
     *
     * @param WebSocketActionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalAction(WebSocketActionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle binary frame signal.
     *
     * @param WebSocketFrameBinarySignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void;

    /**
     * Handle cron signal.
     *
     * @param SignalDataInterface $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void;

    /**
     * Handle a CLI command signal routed to this agent.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source
     * @param string $name Signal name
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void;
}

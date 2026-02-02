<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\WebSocket\WebSocketActionSignalDTO;
use Hilos\DTO\WebSocket\WebSocketHandshakeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketSubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketUnsubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketUpdateSubscriptionSignalDTO;

/**
 * AgentInterface - Interface for agents running in worker processes
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
     * Handle system signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalSystem(SignalDataInterface $data, string $source, string $name): void;

    /**
     * Handle handshake signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketHandshakeSignalDTO $data Signal data
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page subscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketSubscribeSignalDTO $data Signal data
     */
    public function onSignalPageSubscribe(WebSocketSubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page unsubscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketUnsubscribeSignalDTO $data Signal data
     */
    public function onSignalPageUnsubscribe(WebSocketUnsubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page update subscription signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketUpdateSubscriptionSignalDTO $data Signal data
     */
    public function onSignalPageUpdateSubscription(WebSocketUpdateSubscriptionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group subscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketSubscribeSignalDTO $data Signal data
     */
    public function onSignalGroupSubscribe(WebSocketSubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group unsubscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketUnsubscribeSignalDTO $data Signal data
     */
    public function onSignalGroupUnsubscribe(WebSocketUnsubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group update subscription signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketUpdateSubscriptionSignalDTO $data Signal data
     */
    public function onSignalGroupUpdateSubscription(WebSocketUpdateSubscriptionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle action signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketActionSignalDTO $data Signal data
     */
    public function onSignalAction(WebSocketActionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle cron signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void;
}

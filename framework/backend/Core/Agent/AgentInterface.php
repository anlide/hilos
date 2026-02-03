<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\WebSocket\WebSocketActionSignalDTO;
use Hilos\DTO\WebSocket\WebSocketFrameBinarySignalDTO;
use Hilos\DTO\WebSocket\WebSocketHandshakeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketGroupSubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\DTO\WebSocket\WebSocketPageSubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketPageUnsubscribeSignalDTO;
use Hilos\DTO\WebSocket\WebSocketPageUpdateSubscriptionSignalDTO;

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
     * @param WebSocketPageSubscribeSignalDTO $data Signal data
     */
    public function onSignalPageSubscribe(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page unsubscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketPageUnsubscribeSignalDTO $data Signal data
     */
    public function onSignalPageUnsubscribe(WebSocketPageUnsubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle page update subscription signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Signal data
     */
    public function onSignalPageUpdateSubscription(WebSocketPageUpdateSubscriptionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group subscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketGroupSubscribeSignalDTO $data Signal data
     */
    public function onSignalGroupSubscribe(WebSocketGroupSubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group unsubscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketGroupUnsubscribeSignalDTO $data Signal data
     */
    public function onSignalGroupUnsubscribe(WebSocketGroupUnsubscribeSignalDTO $data, string $source, string $name): void;

    /**
     * Handle group update subscription signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $data Signal data
     */
    public function onSignalGroupUpdateSubscription(WebSocketGroupUpdateSubscriptionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle action signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketActionSignalDTO $data Signal data
     */
    public function onSignalAction(WebSocketActionSignalDTO $data, string $source, string $name): void;

    /**
     * Handle binary frame signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param WebSocketFrameBinarySignalDTO $data Signal data
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void;

    /**
     * Handle cron signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void;
}

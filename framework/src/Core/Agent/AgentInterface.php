<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Router\SignalDataInterface;

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
    public function onSignalSystem(string $source, string $name, SignalDataInterface $data): void;

    /**
     * Handle page subscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalPageSubscribe(string $source, string $name, SignalDataInterface $data): void;

    /**
     * Handle page unsubscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalPageUnsubscribe(string $source, string $name, SignalDataInterface $data): void;

    /**
     * Handle page update subscription signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalPageUpdateSubscription(string $source, string $name, SignalDataInterface $data): void;

    /**
     * Handle group subscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalGroupSubscribe(string $source, string $name, SignalDataInterface $data): void;

    /**
     * Handle group unsubscribe signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalGroupUnsubscribe(string $source, string $name, SignalDataInterface $data): void;

    /**
     * Handle group update subscription signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalGroupUpdateSubscription(string $source, string $name, SignalDataInterface $data): void;

    /**
     * Handle action signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalAction(string $source, string $name, SignalDataInterface $data): void;

    /**
     * Handle cron signal
     *
     * @param string $source Signal source
     * @param string $name Signal name
     * @param SignalDataInterface $data Signal data
     */
    public function onSignalCron(string $source, string $name, SignalDataInterface $data): void;
}


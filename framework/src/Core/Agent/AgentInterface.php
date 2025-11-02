<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\DTO\MessageFromAgentDTO;
use Hilos\Core\Agent\DTO\MessageFromDaemonDTO;
use Hilos\Core\Agent\DTO\MessageFromUserDTO;
use Hilos\Core\Agent\DTO\MessageFromWorkerDTO;

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
    public function tick(): void;

    /**
     * Handle message from daemon
     *
     * @param MessageFromDaemonDTO $message Message from daemon
     * @return AgentMessageDTOInterface|null Response DTO (null if no response needed)
     */
    public function handleMessageFromDaemon(MessageFromDaemonDTO $message): ?AgentMessageDTOInterface;

    /**
     * Handle message from user (external source like WebSocket)
     *
     * @param MessageFromUserDTO $message Message from user
     * @return AgentMessageDTOInterface|null Response DTO (null if no response needed)
     */
    public function handleMessageFromUser(MessageFromUserDTO $message): ?AgentMessageDTOInterface;

    /**
     * Handle message from another agent
     *
     * @param MessageFromAgentDTO $message Message from another agent
     * @return AgentMessageDTOInterface|null Response DTO (null if no response needed)
     */
    public function handleMessageFromAgent(MessageFromAgentDTO $message): ?AgentMessageDTOInterface;

    /**
     * Handle message from worker process management
     *
     * @param MessageFromWorkerDTO $message Message from worker
     * @return AgentMessageDTOInterface|null Response DTO (null if no response needed)
     */
    public function handleMessageFromWorker(MessageFromWorkerDTO $message): ?AgentMessageDTOInterface;

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
}


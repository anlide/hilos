<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\DTO\MessageFromUserDTO;
use Hilos\Hilos\BaseDTO;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Utils\Logger;

/**
 * AbstractAgentDaemon - Abstract base class for agent proxies in daemon
 *
 * Provides base implementation for agent proxies. Child classes must implement:
 * - getType() - return agent type
 * - getIndex() - return agent index (can return null)
 * - sendToUser() - forward messages from agent to external user
 */
abstract class AbstractAgentDaemon implements AgentDaemonInterface
{
    /** @var ?WorkerClient Worker client connection */
    private ?WorkerClient $workerClient = null;

    /**
     * Set worker client connection
     *
     * @param WorkerClient $workerClient Worker client connection
     */
    public function setWorkerClient(WorkerClient $workerClient): void
    {
        $this->workerClient = $workerClient;
        $agentId = $this->getId();
        $agentType = $this->getType();
        $workerIndex = $workerClient->getWorkerIndex();

        Logger::debug("Agent {$agentId} linked to WorkerClient [type={$agentType}] [workerIndex={$workerIndex}]");
    }

    /**
     * Get worker client connection
     *
     * @return ?WorkerClient Worker client or null if not set
     */
    public function getWorkerClient(): ?WorkerClient
    {
        return $this->workerClient;
    }

    /**
     * Send message to worker agent (from external user)
     *
     * Default implementation sends message through WorkerClient.
     * Child classes can override for custom routing logic.
     *
     * @param BaseDTO $message Message DTO
     */
    public function sendToAgent(BaseDTO $message): void
    {
        $this->workerClient?->send($message->toJson());
    }

    /**
     * Default implementation - no message forwarding from agent to user
     *
     * Child classes MUST override this method to forward messages to external clients.
     *
     * @param AgentMessageDTOInterface $message Message from agent
     */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Default: do nothing - child classes must implement
    }

    /**
     * Default implementation - forwards to sendToUser()
     *
     * Child classes can override for custom handling.
     *
     * @param AgentMessageDTOInterface $message Message from worker agent
     */
    public function handleMessageFromAgent(AgentMessageDTOInterface $message): void
    {
        $this->sendToUser($message);
    }

    /**
     * Default implementation - forwards to sendToAgent()
     *
     * Child classes can override for custom handling.
     *
     * @param MessageFromUserDTO $message Message from external source
     * @return ?AgentMessageDTOInterface Response DTO
     */
    public function handleMessageFromUser(MessageFromUserDTO $message): ?AgentMessageDTOInterface
    {
        $this->sendToAgent($message);
        return null;
    }

    /**
     * Get agent unique identifier (type + index)
     *
     * Default implementation: "type:index" or "type" if index is null
     *
     * @return string Agent ID
     */
    public function getId(): string
    {
        $index = $this->getIndex();
        if ($index === null) {
            return $this->getType();
        }
        return $this->getType() . ':' . $index;
    }

    /**
     * Default implementation - logs agent start on daemon side
     *
     * Child classes can override this method.
     */
    public function onStart(): void
    {
        $agentId = $this->getId();
        $agentType = $this->getType();
        $workerIndex = $this->workerClient?->getWorkerIndex() ?? 'unknown';

        Logger::debug("Agent {$agentId} started [type={$agentType}] [workerIndex={$workerIndex}]");
    }

    /**
     * Default implementation - logs agent stop on daemon side
     *
     * Child classes can override this method.
     */
    public function onStop(): void
    {
        $agentId = $this->getId();
        $agentType = $this->getType();
        $workerIndex = $this->workerClient?->getWorkerIndex() ?? 'unknown';

        Logger::debug("Agent {$agentId} stopped [type={$agentType}] [workerIndex={$workerIndex}]");
    }
}

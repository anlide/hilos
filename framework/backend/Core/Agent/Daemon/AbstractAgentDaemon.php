<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\BaseDTO;
use Hilos\Constants\AgentConstants;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\DTO\MessageFromUserDTO;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Utils\Logger;

/**
 * AbstractAgentDaemon - Abstract base class for agent proxies in daemon.
 *
 * Provides base implementation for agent proxies. Child classes should define:
 * - AGENT_TYPE constant - agent type identifier
 * - $agentIndex property (set in constructor) - for multi-instance agents, null for singletons
 * - sendToUser() - forward messages from agent to external user
 */
abstract class AbstractAgentDaemon implements AgentDaemonInterface
{
    /** @var string Agent type identifier. Override in child classes. */
    public const string AGENT_TYPE = '';

    /** @var ?string Agent index for multi-instance agents (null for singletons) */
    protected ?string $agentIndex = null;

    /** @var ?WorkerClient Worker client connection */
    private ?WorkerClient $workerClient = null;

    /**
     * @return string Agent type from AGENT_TYPE constant
     */
    public function getType(): string
    {
        return static::AGENT_TYPE;
    }

    /**
     * @return ?string Agent index or null for singleton agents
     */
    public function getIndex(): ?string
    {
        return $this->agentIndex;
    }

    /**
     * Default implementation - fail-safe leader-only.
     *
     * Returns true so every existing agent becomes a cluster-singleton without a
     * code change: on a cluster it runs only on the leader node. Per-node agents
     * override this to return false.
     *
     * @return bool True: run only on the cluster leader
     */
    public function requiresClusterLeadership(): bool
    {
        return true;
    }

    /**
     * Default implementation - no required capabilities.
     *
     * Returns an empty list so an agent runs on any node by default. An agent that needs a
     * node to advertise a capability (a GPU, a licensed binary, a region) overrides this to
     * return the required tags, and the leader hard-checks them before placing it.
     *
     * @return list<string> Required capability tags; empty runs anywhere
     */
    public function requiredCapabilities(): array
    {
        return [];
    }

    /**
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
     * @return bool True when a worker client is attached
     */
    public function hasWorkerClient(): bool
    {
        return $this->workerClient !== null;
    }

    /**
     * @return WorkerClient Worker client connection
     * @throws AgentNotLinkedToWorkerException When the daemon is not yet linked to a worker
     */
    public function getWorkerClient(): WorkerClient
    {
        return $this->workerClient
            ?? throw new AgentNotLinkedToWorkerException($this->getId());
    }

    /**
     * Sends message to worker agent (from external user).
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
     * Default implementation - no message forwarding from agent to user.
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
     * Default implementation - forwards to sendToUser().
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
     * Default implementation - forwards to sendToAgent().
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
     * Gets agent unique identifier (type + index).
     *
     * Default implementation: "type:index" or "type" if index is null.
     *
     * @return string Agent ID
     */
    public function getId(): string
    {
        $index = $this->getIndex();
        if ($index === null) {
            return $this->getType();
        }
        return $this->getType() . AgentConstants::ID_SEPARATOR . $index;
    }

    /**
     * Default implementation - logs agent start on daemon side.
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
     * Default implementation - logs agent stop on daemon side.
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

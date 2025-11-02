<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Utils\DTO\Agent\AgentMessageDTOInterface;
use Hilos\Utils\DTO\Agent\MessageFromAgentDTO;
use Hilos\Utils\DTO\Agent\MessageFromDaemonDTO;
use Hilos\Utils\DTO\Agent\MessageFromUserDTO;
use Hilos\Utils\DTO\Agent\MessageFromWorkerDTO;

/**
 * AbstractAgent - Abstract base class for agents running in worker processes
 *
 * Provides base implementation for agents. Child classes must implement:
 * - getType() - return agent type
 * - getIndex() - return agent index (can return null)
 * - doTick() - agent work logic (override this instead of tick())
 * - Message handling methods (can override for specific sources)
 */
abstract class AbstractAgent implements AgentInterface
{
    /** @var callable|null Callback for sending messages to daemon */
    private $messageSender = null;

    /** @var bool Flag indicating agent should stop */
    private bool $shouldStop = false;

    /**
     * Set message sender callback
     *
     * @param callable $sender Callback function(string $agentId, AgentMessageDTOInterface $dto): void
     */
    public function setMessageSender(callable $sender): void
    {
        $this->messageSender = $sender;
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
     * Send message to daemon
     *
     * @param AgentMessageDTOInterface $dto Message DTO
     */
    protected function sendToDaemon(AgentMessageDTOInterface $dto): void
    {
        if ($this->messageSender !== null) {
            ($this->messageSender)($this->getId(), $dto);
        }
    }

    /**
     * Tick method - checks shouldStop flag and calls doTick() if agent is running
     *
     * If shouldStop flag is set, calls onStop() and marks agent for removal.
     * Otherwise calls doTick() for agent-specific work.
     *
     * Child classes should override doTick() instead of tick().
     */
    public function tick(): void
    {
        // Check if agent requested stop
        if ($this->shouldStop) {
            $this->onStop();
            return;
        }

        // Call agent-specific tick implementation
        $this->doTick();
    }

    /**
     * Agent-specific tick implementation
     *
     * Child classes should override this method for agent-specific work.
     * Called only when agent is not stopping.
     */
    protected function doTick(): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no message handling from daemon
     *
     * Child classes can override this method.
     *
     * @param MessageFromDaemonDTO $message Message from daemon
     * @return AgentMessageDTOInterface|null Response DTO
     */
    public function handleMessageFromDaemon(MessageFromDaemonDTO $message): ?AgentMessageDTOInterface
    {
        // Default: no response
        return null;
    }

    /**
     * Default implementation - no message handling from user
     *
     * Child classes can override this method.
     *
     * @param MessageFromUserDTO $message Message from user
     * @return AgentMessageDTOInterface|null Response DTO
     */
    public function handleMessageFromUser(MessageFromUserDTO $message): ?AgentMessageDTOInterface
    {
        // Default: no response
        return null;
    }

    /**
     * Default implementation - no message handling from agent
     *
     * Child classes can override this method.
     *
     * @param MessageFromAgentDTO $message Message from another agent
     * @return AgentMessageDTOInterface|null Response DTO
     */
    public function handleMessageFromAgent(MessageFromAgentDTO $message): ?AgentMessageDTOInterface
    {
        // Default: no response
        return null;
    }

    /**
     * Default implementation - no message handling from worker
     *
     * Child classes can override this method.
     *
     * @param MessageFromWorkerDTO $message Message from worker
     * @return AgentMessageDTOInterface|null Response DTO
     */
    public function handleMessageFromWorker(MessageFromWorkerDTO $message): ?AgentMessageDTOInterface
    {
        // Default: no response
        return null;
    }

    /**
     * Request agent to stop itself
     *
     * Sets internal flag that will cause onStop() to be called at the start
     * of the next tick. Agent will be removed from worker after onStop() is called.
     *
     * Child classes can call this method to request their own termination.
     */
    protected function selfStop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Check if agent has requested stop
     *
     * @return bool True if agent has requested stop
     */
    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Default implementation - no action on start
     *
     * Child classes can override this method.
     */
    public function onStart(): void
    {
        // Default: do nothing
    }

    /**
     * Called when agent is stopped
     *
     * Must be implemented in child classes to handle cleanup.
     */
    abstract public function onStop(): void;
}


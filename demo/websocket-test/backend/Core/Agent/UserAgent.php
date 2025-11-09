<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent;

use Demo\WebSocketTest\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalRouter;
use Hilos\Logging\Logger\Logger;

/**
 * UserAgent - Regular agent for user management
 *
 * Runs in regular worker process.
 */
class UserAgent extends AbstractAgent
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::SESSION;

    /** @var string User ID */
    private string $userId;

    /**
     * UserAgent constructor
     *
     * @param string $userId User identifier
     * @param SignalRouter $signalRouter Signal router instance
     */
    public function __construct(string $userId, SignalRouter $signalRouter)
    {
        parent::__construct($signalRouter);
        $this->userId = $userId;
    }

    /**
     * Get agent type
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    /**
     * Get agent index
     *
     * User agent index is the user ID
     *
     * @return ?string Agent index (user ID)
     */
    public function getIndex(): ?string
    {
        return $this->userId;
    }

    /**
     * Get user ID
     *
     * @return string User ID
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Called when agent is started
     */
    public function onStart(): void
    {
        Logger::logAgentStart($this->getId(), $this->getType());
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
        Logger::logAgentStop($this->getId(), $this->getType());
    }

    /**
     * Agent-specific tick implementation
     */
    public function onTick(): void
    {
        // TODO: Add user-specific logic here
    }
}

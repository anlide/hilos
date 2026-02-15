<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Hilos\Database\Hilos;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Idea\TruthSourceRegistry;
use Hilos\Logging\Logger\Logger;

/**
 * ModeratorAgent - Regular agent for content moderation
 *
 * Runs in regular worker process. Manages content moderation and AI-based checks.
 */
class ModeratorAgent extends AbstractAgent
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::MODERATOR;

    /**
     * ModeratorAgent constructor
     *
     * @param SignalRouter $signalRouter Signal router instance
     */
    public function __construct(SignalRouter $signalRouter)
    {
        parent::__construct($signalRouter);
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
     * Moderator agent has no index (global singleton)
     *
     * @return ?string Agent index (null for global moderator agent)
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Called when agent is started
     */
    public function onStart(): void
    {
        Logger::logAgentStart($this->getId(), $this->getType());

        // Register this agent as truth source for moderator collection (all keys)
        TruthSourceRegistry::register(Hilos::moderators, true, $this->getId());
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
        // Unregister as truth source
        TruthSourceRegistry::unregister(Hilos::moderators, $this->getId());
        
        Logger::logAgentStop($this->getId(), $this->getType());
    }

    /**
     * Agent-specific tick implementation
     */
    public function onTick(): void
    {
        // TODO: Add moderator-specific logic here
        // For example: process queued messages for moderation, run AI checks, etc.
    }
}

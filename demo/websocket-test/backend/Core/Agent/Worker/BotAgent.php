<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Agent\Worker;

use Demo\WebSocketTest\Constants\AgentType;
use Demo\WebSocketTest\Database\Idea;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Idea\TruthSourceRegistry;
use Hilos\Logging\Logger\Logger;

/**
 * BotAgent - Regular agent for bot management
 *
 * Runs in regular worker process. Manages bot interactions and chat behavior.
 */
class BotAgent extends AbstractAgent
{
    /** @var string Agent type */
    private const string AGENT_TYPE = AgentType::BOT;

    /**
     * BotAgent constructor
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
     * Bot agent has no index (global singleton)
     *
     * @return ?string Agent index (null for global bot agent)
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

        // Register this agent as truth source for bot table (all keys)
        TruthSourceRegistry::register('bot', true, $this->getId());
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
        // Unregister as truth source
        TruthSourceRegistry::unregister('bot', $this->getId());
        
        Logger::logAgentStop($this->getId(), $this->getType());
    }

    /**
     * Agent-specific tick implementation
     */
    public function onTick(): void
    {
        // TODO: Add bot-specific logic here
        // For example: process queued bot messages, handle bot responses, etc.
    }
}

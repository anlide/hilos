<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Database\DbChatContext;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Utils\Logger;

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

        // Register this agent as truth source for bot collection (all keys)
        TruthSourceRegistry::register(DbChatContext::bots, true, $this->getId());
    }

    /**
     * Called when agent is stopped
     */
    public function onStop(): void
    {
        // Unregister as truth source
        TruthSourceRegistry::unregister(DbChatContext::bots, $this->getId());
        
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

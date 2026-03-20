<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\AI\Agent\ChatAiAgentFactory;
use Hilos\AI\Agent\AiAgentInterface;
use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;

/**
 * DemoHilosGuardianAgent - Concrete Hilos guardian agent for chat demo.
 *
 * Handles Hilos guardian page (project validation robots) in the demo project.
 */
class DemoHilosGuardianAgent extends AbstractHilosGuardianAgent
{
    /** @var array<string, AiAgentInterface> */
    private array $guardianAiAgents = [];

    /**
     * Create all registered guardian AI agents for the demo project.
     */
    public function onStart(): void
    {
        $this->guardianAiAgents = ChatAiAgentFactory::createAll();
    }

    /**
     * Run one tick for each instantiated guardian AI agent.
     */
    public function onTick(): void
    {
        foreach ($this->guardianAiAgents as $guardianAiAgent) {
            $guardianAiAgent->onTick();
        }
    }

    /**
     * Release instantiated guardian AI agents.
     */
    public function onStop(): void
    {
        $this->guardianAiAgents = [];
    }
}

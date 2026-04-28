<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\AI\Agent\ChatAiAgentFactory;
use Hilos\AI\Agent\AiAgentInterface;
use Hilos\AI\Agent\GuardianAiAgentId;
use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;

/**
 * DemoHilosGuardianAgent - Concrete Hilos guardian agent for chat demo.
 *
 * Handles Hilos guardian page (project validation robots) in the demo project.
 */
class DemoHilosGuardianAgent extends AbstractHilosGuardianAgent
{
    private const array DEMO_ONLY_AGENT_IDS = [
        'oss_budget_distribution',
    ];

    /** @var array<string, AiAgentInterface> */
    private array $guardianAiAgents = [];

    /**
     * Create all registered guardian AI agents for the demo project.
     */
    public function onStart(): void
    {
        $this->guardianAiAgents = ChatAiAgentFactory::createAll();
        $this->getGuardianRunStatuses();
    }

    /**
     * Run one tick for each instantiated guardian AI agent.
     */
    public function onTick(): void
    {
        $this->processPendingGuardianRuns();

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
        $this->resetGuardianRunStates();
    }

    /**
     * Get all guardian agent ids supported by the demo UI.
     *
     * @return list<string> Guardian agent identifiers
     */
    protected function getKnownGuardianAgentIds(): array
    {
        $agentIds = array_map(
            static fn(GuardianAiAgentId $agentId): string => $agentId->value,
            GuardianAiAgentId::cases(),
        );

        return array_values(array_unique([
            ...$agentIds,
            ...self::DEMO_ONLY_AGENT_IDS,
        ]));
    }
}

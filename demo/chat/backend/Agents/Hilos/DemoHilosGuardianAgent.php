<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\AI\Agent\ChatAiAgentFactory;
use Hilos\AI\Agent\AiAgentInterface;
use Hilos\AI\Agent\GuardianAiAgentId;
use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;
use Random\RandomException;

/**
 * Chat demo guardian agent that wires project AI agents into the Hilos guardian page.
 *
 * Framework guardian agents come from the shared catalog; demo-only ids are appended for UI compatibility.
 */
class DemoHilosGuardianAgent extends AbstractHilosGuardianAgent
{
    /** @var list<string> */
    private const array DEMO_ONLY_AGENT_IDS = [
        'oss_budget_distribution',
    ];

    /** @var array<string, AiAgentInterface> */
    private array $guardianAiAgents = [];

    /**
     * Instantiates chat project guardian AI agents and initializes in-memory run statuses.
     */
    public function onStart(): void
    {
        $this->guardianAiAgents = ChatAiAgentFactory::createAll();
        $this->getGuardianRunStatuses();
    }

    /**
     * Finalizes pending guardian runs and ticks each instantiated AI agent once.
     */
    public function onTick(): void
    {
        $this->processPendingGuardianRuns();

        foreach ($this->guardianAiAgents as $guardianAiAgent) {
            $guardianAiAgent->onTick();
        }
    }

    /**
     * Releases AI agent instances and clears guardian run state.
     */
    public function onStop(): void
    {
        $this->guardianAiAgents = [];
        $this->resetGuardianRunStates();
    }

    /**
     * Returns framework guardian ids plus demo-only guardian ids supported by the UI.
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

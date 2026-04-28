<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\AI\Agent\ChatAiAgentFactory;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\AI\Agent\AiAgentInterface;
use Hilos\AI\Agent\GuardianAiAgentId;
use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;

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
     * Instantiates chat project guardian AI agents and initializes runtime run statuses.
     */
    public function onStart(): void
    {
        $this->registerRtTruthSource(RtChatContext::guardianAgentStatuses);

        $this->guardianAiAgents = ChatAiAgentFactory::createAll();
        Hilos::$rt->guardianAgentStatuses->actions->syncStatuses($this->getGuardianRunStatuses());
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
        Hilos::$rt->guardianAgentStatuses->actions->clear();
        $this->resetGuardianRunStates();
    }

    /**
     * Runtime collection used by the project signal mapper for guardian status fan-out.
     *
     * @return string Guardian status runtime collection key
     */
    protected function getGuardianRunStatusRtCollectionKey(): string
    {
        return RtChatContext::guardianAgentStatuses;
    }

    /**
     * Mirror guardian status changes into runtime state before daemon fan-out.
     *
     * @param string $agentId Guardian agent identifier
     * @param GuardianRunStatus $status Run status
     */
    protected function onGuardianRunStatusChanged(string $agentId, GuardianRunStatus $status): void
    {
        Hilos::$rt->guardianAgentStatuses->actions->setStatus($agentId, $status);
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

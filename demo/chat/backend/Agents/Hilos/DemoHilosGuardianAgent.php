<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\AI\Agent\ChatAiAgentFactory;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AI\Agent\AiAgentInterface;
use Hilos\AI\Agent\GuardianAiAgentId;
use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use LogicException;

/**
 * Chat demo guardian agent that wires project AI agents into the Hilos guardian page.
 *
 * Framework guardian agents come from the shared catalog; demo-only ids are appended for UI compatibility.
 */
final class DemoHilosGuardianAgent extends AbstractHilosGuardianAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The run status of each guardian AI agent,
     *     which the browser rows of the guardian screen are built from.
     */
    public const array OWNS_RT = [ChatRtContext::guardianAgentStatuses => TruthSourceOperation::BY_KIND];

    /** @var list<string> */
    private const array DEMO_ONLY_AGENT_IDS = [
        'oss_budget_distribution',
    ];

    /** @var array<string, AiAgentInterface> */
    private array $guardianAiAgents = [];

    /**
     * Instantiates chat project guardian AI agents and initializes runtime run statuses.
     *
     * @throws LogicException When an internal invariant is violated
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is unavailable
     * @throws RtActionsCollectionNameNullException When the runtime collection has no name
     * @throws RtActionsItemClassException When the runtime item class is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent cannot write the runtime collection
     * @throws SourceChangeSubscriberException When a source subscriber fails
     */
    public function onStart(): void
    {
        $this->guardianAiAgents = ChatAiAgentFactory::createAll();
        Hilos::$rt->guardianAgentStatuses->actions->syncStatuses($this->getGuardianRunStatuses());
    }

    /**
     * Finalizes pending guardian runs and ticks each instantiated AI agent once.
     *
     * @throws HilosException When a guardian run status cannot be mirrored
     * @throws LogicException When a runtime item factory rejects a status row
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
     *
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is unavailable
     * @throws RtActionsCollectionNameNullException When the runtime collection has no name
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent cannot write the runtime collection
     * @throws InvalidArgumentException When a runtime collection argument is invalid
     */
    public function onStop(): void
    {
        $this->guardianAiAgents = [];
        Hilos::$rt->guardianAgentStatuses->actions->clear();
        $this->resetGuardianRunStates();
    }

    /**
     * Mirror guardian status changes into runtime state for browser rows.
     *
     * @param string $agentId Guardian agent identifier
     * @param GuardianRunStatus $status Run status
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is unavailable
     * @throws RtActionsCollectionNameNullException When the runtime collection has no name
     * @throws RtActionsItemClassException When the runtime item class is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent cannot write the runtime collection
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws LogicException When an internal invariant is violated
     */
    protected function onGuardianRunStatusChanged(string $agentId, GuardianRunStatus $status): void
    {
        $statusItem = Hilos::$rt->guardianAgentStatuses[$agentId]
            ?? Hilos::$rt->guardianAgentStatuses->actions->create($agentId, $status);
        if ($statusItem->status !== $status->value) {
            $statusItem->actions->setStatus($status);
        }
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

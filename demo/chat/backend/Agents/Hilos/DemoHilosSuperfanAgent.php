<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\AI\Agent\ChatAiAgentFactory;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AI\Agent\AiAgentInterface;
use Hilos\AI\Agent\SuperfanAiAgentId;
use Hilos\Core\Agent\Hilos\AbstractHilosSuperfanAgent;
use Hilos\Core\Agent\Hilos\SuperfanRunStatus;
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
 * Chat demo superfans agent that wires project AI agents into the Hilos superfans page.
 *
 * Framework superfan agents come from the shared catalog; demo-only ids are appended for UI compatibility.
 */
final class DemoHilosSuperfanAgent extends AbstractHilosSuperfanAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The run status of each superfan AI agent,
     *     which the browser rows of the superfan screen are built from.
     */
    public const array OWNS_RT = [ChatRtContext::superfanAgentStatuses => TruthSourceOperation::BY_KIND];

    /** @var list<string> */
    private const array DEMO_ONLY_AGENT_IDS = [
        'oss_budget_distribution',
    ];

    /** @var array<string, AiAgentInterface> */
    private array $superfanAiAgents = [];

    /**
     * Instantiates chat project superfan AI agents and initializes runtime run statuses.
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
        $this->superfanAiAgents = ChatAiAgentFactory::createAll();
        Hilos::$rt->superfanAgentStatuses->actions->syncStatuses($this->getSuperfanRunStatuses());
    }

    /**
     * Finalizes pending superfan runs and ticks each instantiated AI agent once.
     *
     * @throws HilosException When a superfan run status cannot be mirrored
     * @throws LogicException When a runtime item factory rejects a status row
     */
    public function onTick(): void
    {
        $this->processPendingSuperfanRuns();

        foreach ($this->superfanAiAgents as $superfanAiAgent) {
            $superfanAiAgent->onTick();
        }
    }

    /**
     * Releases AI agent instances and clears superfan run state.
     *
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is unavailable
     * @throws RtActionsCollectionNameNullException When the runtime collection has no name
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent cannot write the runtime collection
     * @throws InvalidArgumentException When a runtime collection argument is invalid
     */
    public function onStop(): void
    {
        $this->superfanAiAgents = [];
        Hilos::$rt->superfanAgentStatuses->actions->clear();
        $this->resetSuperfanRunStates();
    }

    /**
     * Mirror superfan status changes into runtime state for browser rows.
     *
     * @param string $agentId Superfan agent identifier
     * @param SuperfanRunStatus $status Run status
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is unavailable
     * @throws RtActionsCollectionNameNullException When the runtime collection has no name
     * @throws RtActionsItemClassException When the runtime item class is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent cannot write the runtime collection
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws LogicException When an internal invariant is violated
     */
    protected function onSuperfanRunStatusChanged(string $agentId, SuperfanRunStatus $status): void
    {
        $statusItem = Hilos::$rt->superfanAgentStatuses[$agentId]
            ?? Hilos::$rt->superfanAgentStatuses->actions->create($agentId, $status);
        if ($statusItem->status !== $status->value) {
            $statusItem->actions->setStatus($status);
        }
    }

    /**
     * Returns framework superfan ids plus demo-only superfan ids supported by the UI.
     *
     * @return list<string> Superfan agent identifiers
     */
    protected function getKnownSuperfanAgentIds(): array
    {
        $agentIds = array_map(
            static fn(SuperfanAiAgentId $agentId): string => $agentId->value,
            SuperfanAiAgentId::cases(),
        );

        return array_values(array_unique([
            ...$agentIds,
            ...self::DEMO_ONLY_AGENT_IDS,
        ]));
    }
}

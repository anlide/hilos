<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Runtime\State\Collection\GuardianAgentStatuses as StateGuardianAgentStatuses;
use Demo\Chat\Runtime\State\Item\GuardianAgentStatus as StateGuardianAgentStatus;
use Demo\Chat\Runtime\View\Collection\GuardianAgentStatuses;
use Demo\Chat\Runtime\View\Item\GuardianAgentStatus as ViewGuardianAgentStatus;
use Hilos\Core\Agent\Hilos\GuardianRunStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use LogicException;

/**
 * Write API for guardian run statuses.
 *
 * @extends RtActions<ViewGuardianAgentStatus, GuardianAgentStatuses, StateGuardianAgentStatuses>
 * @property-read StateGuardianAgentStatuses $stateCollection
 */
final class GuardianAgentStatusesActions extends RtActions
{
    /**
     * Seed statuses from the guardian agent's current map.
     *
     * @param array<string, string> $statuses Status map keyed by guardian agent id
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function syncStatuses(array $statuses): void
    {
        foreach ($statuses as $agentId => $status) {
            $runStatus = GuardianRunStatus::tryFrom($status) ?? GuardianRunStatus::NOT_STARTED;
            $item = $this->collection[$agentId] ?? $this->create($agentId, $runStatus);
            if ($item->status !== $runStatus->value) {
                $item->actions->setStatus($runStatus);
            }
        }
    }

    /**
     * Create one guardian run status row.
     *
     * @param string $agentId Guardian agent identifier
     * @param GuardianRunStatus $status Initial guardian run status
     * @return ViewGuardianAgentStatus Created status row
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function create(string $agentId, GuardianRunStatus $status): ViewGuardianAgentStatus
    {
        $state = StateGuardianAgentStatus::create($agentId, $status);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Remove every guardian status row from runtime state.
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function clear(): void
    {
        $this->clearAllStates();
    }

    /**
     * Narrows parent return type to this collection's RtItem.
     */
    protected function createRtItemFromState(RtState &$state): ViewGuardianAgentStatus
    {
        $item = parent::createRtItemFromState($state);
        if (!$item instanceof ViewGuardianAgentStatus) {
            throw new LogicException('GuardianAgentStatuses item factory must return ' . ViewGuardianAgentStatus::class);
        }

        return $item;
    }
}

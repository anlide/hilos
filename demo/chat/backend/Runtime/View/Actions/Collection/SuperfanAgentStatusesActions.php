<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Runtime\State\Collection\SuperfanAgentStatuses as StateSuperfanAgentStatuses;
use Demo\Chat\Runtime\State\Item\SuperfanAgentStatus as StateSuperfanAgentStatus;
use Demo\Chat\Runtime\View\Collection\SuperfanAgentStatuses;
use Demo\Chat\Runtime\View\Item\SuperfanAgentStatus as ViewSuperfanAgentStatus;
use Hilos\Core\Agent\Hilos\SuperfanRunStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Exception\InvalidArgumentException;

/**
 * Write API for superfan run statuses.
 *
 * @extends RtActions<ViewSuperfanAgentStatus, SuperfanAgentStatuses, StateSuperfanAgentStatuses>
 * @property-read StateSuperfanAgentStatuses $stateCollection
 */
final class SuperfanAgentStatusesActions extends RtActions
{
    /**
     * Seed statuses from the superfan agent's current map.
     *
     * @param array<string, string> $statuses Status map keyed by superfan agent id
     * @throws RtActionsCallbackNotSetException When an item factory or forget-cached-item callback is not configured
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws LogicException When the item factory returns the wrong status type
     */
    public function syncStatuses(array $statuses): void
    {
        foreach ($statuses as $agentId => $status) {
            $runStatus = SuperfanRunStatus::tryFrom($status) ?? SuperfanRunStatus::NOT_STARTED;
            $item = $this->collection[$agentId] ?? $this->create($agentId, $runStatus);
            if ($item->status !== $runStatus->value) {
                $item->actions->setStatus($runStatus);
            }
        }
    }

    /**
     * Create one superfan run status row.
     *
     * @param string $agentId Superfan agent identifier
     * @param SuperfanRunStatus $status Initial superfan run status
     * @return ViewSuperfanAgentStatus Created status row
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws LogicException When the item factory returns the wrong status type
     */
    public function create(string $agentId, SuperfanRunStatus $status): ViewSuperfanAgentStatus
    {
        $state = StateSuperfanAgentStatus::create($agentId, $status);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Remove every superfan status row from runtime state.
     *
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When an argument is invalid
     */
    public function clear(): void
    {
        $this->clearAllStates();
    }

    /**
     * Narrows parent return type to this collection's RtItem.
     *
     * @param RtState $state State the item is built over
     * @return ViewSuperfanAgentStatus Read wrapper around the state
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     * @throws LogicException When an internal invariant is violated
     */
    protected function createRtItemFromState(RtState $state): ViewSuperfanAgentStatus
    {
        $item = parent::createRtItemFromState($state);
        if (!$item instanceof ViewSuperfanAgentStatus) {
            throw new LogicException(
                'SuperfanAgentStatuses item factory must return ' . ViewSuperfanAgentStatus::class,
            );
        }

        return $item;
    }
}

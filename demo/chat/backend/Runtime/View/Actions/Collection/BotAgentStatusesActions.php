<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Runtime\State\Collection\BotAgentStatuses as StateBotAgentStatuses;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Collection\BotAgentStatuses;
use Demo\Chat\Runtime\View\Item\BotAgentStatus as ViewBotAgentStatus;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use LogicException;

/**
 * Write API for bot agent lifecycle statuses.
 *
 * @extends RtActions<ViewBotAgentStatus, BotAgentStatuses, StateBotAgentStatuses>
 * @property-read StateBotAgentStatuses $stateCollection
 */
final class BotAgentStatusesActions extends RtActions
{
    /**
     * Mark one bot agent as joined.
     *
     * @param int $botId Bot database id
     * @return ViewBotAgentStatus Updated status row
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function markJoined(int $botId): ViewBotAgentStatus
    {
        return $this->setStatus($botId, StateBotAgentStatus::STATUS_JOINED);
    }

    /**
     * Mark one bot agent as left.
     *
     * @param int $botId Bot database id
     * @return ViewBotAgentStatus Updated status row
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function markLeft(int $botId): ViewBotAgentStatus
    {
        return $this->setStatus($botId, StateBotAgentStatus::STATUS_LEFT);
    }

    /**
     * Remove every bot lifecycle status row from runtime state.
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
    protected function createRtItemFromState(RtState &$state): ViewBotAgentStatus
    {
        $item = parent::createRtItemFromState($state);
        if (!$item instanceof ViewBotAgentStatus) {
            throw new LogicException('BotAgentStatuses item factory must return ' . ViewBotAgentStatus::class);
        }

        return $item;
    }

    /**
     * @param int $botId Bot database id
     * @param string $status Lifecycle marker
     * @return ViewBotAgentStatus Updated status row
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    private function setStatus(int $botId, string $status): ViewBotAgentStatus
    {
        $this->ensureCanWrite();
        $id = (string)$botId;
        $state = $this->stateCollection->get($id);
        if (!$state instanceof StateBotAgentStatus) {
            $state = StateBotAgentStatus::create($botId, $status);
            $this->addStateToCollection($state);

            return $this->createRtItemFromState($state);
        }

        $state->status = $status;
        $state->updatedAt = time();
        $state->sync();
        $this->clearCollectionCache();

        return $this->createRtItemFromState($state);
    }
}

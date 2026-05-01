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
     * Ensure a lifecycle status row exists for the bot.
     *
     * @param int $botId Bot database id
     * @return ViewBotAgentStatus Read wrapper around the ensured state
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function ensure(int $botId): ViewBotAgentStatus
    {
        $this->ensureCanWriteState((string)$botId);

        $existing = $this->stateCollection->get((string)$botId);
        if ($existing instanceof StateBotAgentStatus) {
            return $this->createRtItemFromState($existing);
        }

        $state = StateBotAgentStatus::create($botId, StateBotAgentStatus::STATUS_LEFT);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Create one bot agent status row.
     *
     * @param int $botId Bot database id
     * @param string $status Initial lifecycle marker
     * @return ViewBotAgentStatus Created status row
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function create(int $botId, string $status): ViewBotAgentStatus
    {
        $state = StateBotAgentStatus::create($botId, $status);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
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

}

<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Runtime\State\Collection\UserStates as StateUserStates;
use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Runtime\View\Collection\UserStates;
use Demo\Chat\Runtime\View\Item\ChatUserState as ViewChatUserState;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use LogicException;

/**
 * Write API for per-user chat runtime state.
 *
 * Binary upload sessions live on connections; uploaded files waiting for send
 * live in attachment drafts.
 *
 * @extends RtActions<ViewChatUserState, UserStates, StateUserStates>
 * @property-read StateUserStates $stateCollection
 */
final class UserStatesActions extends RtActions
{
    /**
     * Ensure a row exists for the user.
     *
     * @param int $userId Database user id
     * @return ViewChatUserState Read wrapper around the ensured state
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function ensure(int $userId): ViewChatUserState
    {
        $this->ensureCanWrite();
        $id = (string)$userId;
        $stateCollection = $this->getStateCollection();
        $existing = $stateCollection->get($id);
        if ($existing instanceof StateChatUserState) {
            return $this->createRtItemFromState($existing);
        }
        $state = StateChatUserState::createEmpty($userId);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Narrows parent return type to this collection's RtItem.
     */
    protected function createRtItemFromState(RtState &$state): ViewChatUserState
    {
        $item = parent::createRtItemFromState($state);
        if (!$item instanceof ViewChatUserState) {
            throw new LogicException('UserStates item factory must return ' . ViewChatUserState::class);
        }

        return $item;
    }

    /**
     * Remove every per-user runtime row from the current worker state.
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

}

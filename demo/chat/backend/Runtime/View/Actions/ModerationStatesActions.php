<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions;

use Demo\Chat\Runtime\State\Item\ModerationState as StateModerationState;
use Demo\Chat\Runtime\View\Item\ModerationState as RuntimeModerationState;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\RtActions;

/**
 * ModerationStatesActions - write operations for moderation states.
 *
 * Usage:
 *   Hilos::$rt->moderationStates->actions->set($userId, $message);
 *   Hilos::$rt->moderationStates->actions->clear($userId);
 */
final class ModerationStatesActions extends RtActions
{
    /**
     * Upsert moderation state for user.
     *
     * @param int $userId User ID
     * @param string $message Message currently being moderated
     * @return RuntimeModerationState Created/updated runtime wrapper
     * @throws RtActionsCallbackNotSetException If callback for creating Rt item is not set
     */
    public function set(int $userId, string $message): RuntimeModerationState
    {
        $state = StateModerationState::create($userId, $message);
        $this->addStateToCollection($state);
        return $this->createRtItemFromState($state);
    }

    /**
     * Remove moderation state for user.
     *
     * Must clear View cache so that subsequent access returns null (state removed).
     *
     * @param int $userId User ID
     * @throws RtActionsCallbackNotSetException If callback for creating Rt item is not set
     */
    public function clear(int $userId): void
    {
        $this->removeStateFromCollection((string)$userId);
        $this->clearCollectionCache();
    }
}

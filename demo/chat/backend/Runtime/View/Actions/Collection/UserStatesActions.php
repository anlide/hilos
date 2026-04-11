<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Runtime\View\Collection\UserStates;
use Demo\Chat\Runtime\View\Item\ChatUserState as ViewChatUserState;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;

/**
 * Write API for per-user chat runtime state (text moderation only).
 *
 * File upload and file moderation UI live on {@see \Demo\Chat\Runtime\State\Item\Connection}.
 *
 * @extends RtActions<ViewChatUserState, UserStates>
 */
final class UserStatesActions extends RtActions
{
    /**
     * Ensure a {@see StateChatUserState} row exists for the user (creates an empty template if missing).
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
        $sc = $this->getStateCollection();
        $existing = $sc->get($id);
        if ($existing instanceof StateChatUserState) {
            return $this->createRtItemFromState($existing);
        }
        $state = StateChatUserState::createEmpty($userId);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Narrows parent return type to this collection's RtItem ({@see ViewChatUserState}).
     */
    protected function createRtItemFromState(RtState &$state): ViewChatUserState
    {
        $item = parent::createRtItemFromState($state);
        if (!$item instanceof ViewChatUserState) {
            throw new \LogicException('UserStates item factory must return ' . ViewChatUserState::class);
        }

        return $item;
    }

    /**
     * Create an empty {@see StateChatUserState} for every user in the database (demo startup seed).
     *
     * Skips ids that already have a row in the runtime collection.
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function seedAllFromDb(): void
    {
        $this->ensureCanWrite();
        $sc = $this->getStateCollection();
        foreach (Hilos::$db->users as $user) {
            if ($user->id === null) {
                continue;
            }
            $id = (string)$user->id;
            if ($sc->has($id)) {
                continue;
            }
            $state = StateChatUserState::createEmpty((int)$user->id);
            $this->addStateToCollection($state);
        }
        $this->clearCollectionCache();
    }

    /**
     * Store pending message text for LLM moderation and bump {@see StateChatUserState::moderationUpdatedAt}.
     *
     * @param int $userId Database user id
     * @param string $message Raw message text from the client
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function setTextModerationMessage(int $userId, string $message): void
    {
        $this->applyDiffToState($this->stateRowForMutation($userId), [
            StateChatUserState::moderationMessage => $message,
            StateChatUserState::moderationUpdatedAt => time(),
        ]);
        $this->clearCollectionCache();
    }

    /**
     * Clear pending text moderation for the user.
     *
     * @param int $userId Database user id
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function clearTextModerationMessage(int $userId): void
    {
        $this->applyDiffToState($this->stateRowForMutation($userId), [
            StateChatUserState::moderationMessage => '',
            StateChatUserState::moderationUpdatedAt => time(),
        ]);
        $this->clearCollectionCache();
    }

    /**
     * Apply a partial update to the user's runtime state (keys must match {@see StateChatUserState} fields).
     *
     * @param int $userId Database user id
     * @param array<string, mixed> $diff Field name => value
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function applyDiffForUser(int $userId, array $diff): void
    {
        if ($diff === []) {
            return;
        }
        $this->applyDiffToState($this->stateRowForMutation($userId), $diff);
        $this->clearCollectionCache();
    }

    /**
     * Backing state row for a write path (not a public read API — use {@see ViewChatUserState} for reads).
     *
     * @throws \RuntimeException If state is missing (call {@see ensure()} first)
     */
    private function stateRowForMutation(int $userId): StateChatUserState
    {
        $this->ensureCanWrite();
        $raw = $this->getStateCollection()->get((string)$userId);
        if (!$raw instanceof StateChatUserState) {
            throw new \RuntimeException('ChatUserState missing for userId=' . $userId . '; call ensure() first');
        }

        return $raw;
    }
}

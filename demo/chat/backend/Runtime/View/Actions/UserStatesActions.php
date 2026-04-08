<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Runtime\View\Item\ChatUserState as ViewChatUserState;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\RtActions;

/**
 * UserStatesActions - write operations for per-user chat runtime state.
 *
 * @extends RtActions<ViewChatUserState>
 */
final class UserStatesActions extends RtActions
{
    /**
     * Ensure a ChatUserState row exists for the user (empty template).
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
     * Create empty ChatUserState for every user in the database (demo seed).
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
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function setTextModerationMessage(int $userId, string $message): void
    {
        $state = $this->getExistingStateOrThrow($userId);
        $this->applyDiffToState($state, [
            StateChatUserState::moderationMessage => $message,
            StateChatUserState::moderationUpdatedAt => time(),
        ]);
        $this->clearCollectionCache();
    }

    /**
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function clearTextModerationMessage(int $userId): void
    {
        $state = $this->getExistingStateOrThrow($userId);
        $this->applyDiffToState($state, [
            StateChatUserState::moderationMessage => '',
            StateChatUserState::moderationUpdatedAt => time(),
        ]);
        $this->clearCollectionCache();
    }

    /**
     * Apply a partial update to the user's runtime state (file upload paths, etc.).
     *
     * @param array<string, mixed> $diff
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
        $state = $this->getExistingStateOrThrow($userId);
        $this->applyDiffToState($state, $diff);
        $this->clearCollectionCache();
    }

    /**
     * Clear file session, file moderation UI, and upload progress for one user.
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function clearAllFileRuntimeForUser(int $userId): void
    {
        $state = $this->getExistingStateOrThrow($userId);
        $diff = array_merge(
            StateChatUserState::diffClearFileSession(),
            StateChatUserState::diffClearFileModerationUi(),
            StateChatUserState::diffClearFileProgress(),
        );
        $this->applyDiffToState($state, $diff);
        $this->clearCollectionCache();
    }

    /**
     * Clear file runtime fields for every user (e.g. disk wipe).
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function clearAllFileRuntimeForAllUsers(): void
    {
        $this->ensureCanWrite();
        foreach ($this->getStateCollection() as $state) {
            if (!$state instanceof StateChatUserState) {
                continue;
            }
            $diff = array_merge(
                StateChatUserState::diffClearFileSession(),
                StateChatUserState::diffClearFileModerationUi(),
                StateChatUserState::diffClearFileProgress(),
            );
            $this->applyDiffToState($state, $diff);
        }
        $this->clearCollectionCache();
    }

    private function getExistingStateOrThrow(int $userId): StateChatUserState
    {
        $this->ensureCanWrite();
        $raw = $this->getStateCollection()->get((string)$userId);
        if (!$raw instanceof StateChatUserState) {
            throw new \RuntimeException('ChatUserState missing for userId=' . $userId . '; call ensure() first');
        }

        return $raw;
    }
}

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
use OutOfBoundsException;

/**
 * Write API for per-user chat runtime state (text moderation only).
 *
 * File upload and file moderation UI live on the connection runtime state.
 *
 * @extends RtActions<ViewChatUserState, UserStates, StateUserStates>
 * @property-read StateUserStates $stateCollection
 */
final class UserStatesActions extends RtActions
{
    /**
     * Minimum interval in seconds between chat messages from the same user.
     */
    private const int MESSAGE_RATE_LIMIT_SECONDS = 10;

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

    /**
     * Whether the user is outside the message rate-limit window.
     *
     * Uses {@see self::MESSAGE_RATE_LIMIT_SECONDS} minus one second effective window to reduce
     * false blocks from client timer drift.
     *
     * @param int $userId Database user id
     * @return bool True when the user may submit another text message
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function canSendMessage(int $userId): bool
    {
        $state = $this->stateCollection->get((string)$userId);
        $lastMessageSentAt = $state?->lastMessageSentAt ?? 0.0;

        return (microtime(true) - $lastMessageSentAt) >= self::MESSAGE_RATE_LIMIT_SECONDS - 1;
    }

    /**
     * Record a successful moderated text message publish for rate limiting.
     *
     * @param int $userId Database user id
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function recordMessageSent(int $userId): void
    {
        $this->ensure($userId);

        $this->stateCollection[$userId]->lastMessageSentAt = microtime(true);
        $this->stateCollection[$userId]->sync();

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
     * @throws OutOfBoundsException When user runtime state is not initialized
     */
    public function setTextModerationMessage(int $userId, string $message): void
    {
        $this->ensureCanWrite();

        $this->stateCollection[$userId]->moderationMessage = $message;
        $this->stateCollection[$userId]->moderationUpdatedAt = time();
        $this->stateCollection[$userId]->sync();

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
     * @throws OutOfBoundsException When user runtime state is not initialized
     */
    public function clearTextModerationMessage(int $userId): void
    {
        $this->ensureCanWrite();

        $this->stateCollection[$userId]->moderationMessage = '';
        $this->stateCollection[$userId]->moderationUpdatedAt = time();
        $this->stateCollection[$userId]->sync();

        $this->clearCollectionCache();
    }
}

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

    /**
     * Record an accepted outbound submit for rate limiting.
     *
     * @param int $userId Database user id
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function recordOutboundSubmitted(int $userId): void
    {
        $this->ensure($userId);

        $this->stateCollection[$userId]->lastOutboundSubmittedAt = microtime(true);
        $this->stateCollection[$userId]->sync();

        $this->clearCollectionCache();
    }

    /**
     * Start a user-visible outbound moderation state.
     *
     * @param int $userId Database user id
     * @param string $requestId Moderation request id
     * @param string $message Submitted message text
     * @param list<string> $attachmentDraftIds Submitted attachment draft ids
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     * @throws OutOfBoundsException When user runtime state is not initialized
     */
    public function startOutboundModeration(
        int $userId,
        string $requestId,
        string $message,
        array $attachmentDraftIds,
    ): void {
        $this->ensureCanWrite();

        $this->stateCollection[$userId]->outboundModerationRequestId = $requestId;
        $this->stateCollection[$userId]->outboundModerationPhase = 'checking';
        $this->stateCollection[$userId]->outboundModerationMessage = $message;
        $this->stateCollection[$userId]->outboundModerationAttachmentDraftIdsJson = json_encode(array_values($attachmentDraftIds)) ?: '[]';
        $this->stateCollection[$userId]->outboundModerationReason = '';
        $this->stateCollection[$userId]->outboundModerationUpdatedAt = time();
        $this->stateCollection[$userId]->sync();

        $this->clearCollectionCache();
    }

    /**
     * Mark the current outbound moderation as failed for user-visible retry.
     *
     * @param int $userId Database user id
     * @param string $requestId Moderation request id to match
     * @param string $phase Failure phase: rejected or unavailable
     * @param string $reason User-visible reason
     * @return bool True when the state matched and was updated
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     * @throws OutOfBoundsException When user runtime state is not initialized
     */
    public function failOutboundModeration(int $userId, string $requestId, string $phase, string $reason): bool
    {
        $this->ensureCanWrite();

        if ($this->stateCollection[$userId]->outboundModerationRequestId !== $requestId) {
            return false;
        }

        $this->stateCollection[$userId]->outboundModerationPhase = $phase;
        $this->stateCollection[$userId]->outboundModerationReason = $reason;
        $this->stateCollection[$userId]->outboundModerationUpdatedAt = time();
        $this->stateCollection[$userId]->sync();

        $this->clearCollectionCache();

        return true;
    }

    /**
     * Clear current outbound moderation state after approval.
     *
     * @param int $userId Database user id
     * @param string $requestId Moderation request id to match
     * @return bool True when the state matched and was cleared
     *
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     * @throws OutOfBoundsException When user runtime state is not initialized
     */
    public function clearOutboundModeration(int $userId, string $requestId): bool
    {
        $this->ensureCanWrite();

        if ($this->stateCollection[$userId]->outboundModerationRequestId !== $requestId) {
            return false;
        }

        $this->stateCollection[$userId]->outboundModerationRequestId = '';
        $this->stateCollection[$userId]->outboundModerationPhase = '';
        $this->stateCollection[$userId]->outboundModerationMessage = '';
        $this->stateCollection[$userId]->outboundModerationAttachmentDraftIdsJson = '[]';
        $this->stateCollection[$userId]->outboundModerationReason = '';
        $this->stateCollection[$userId]->outboundModerationUpdatedAt = time();
        $this->stateCollection[$userId]->sync();

        $this->clearCollectionCache();

        return true;
    }
}

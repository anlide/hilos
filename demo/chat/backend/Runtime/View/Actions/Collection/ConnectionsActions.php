<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Actions\Item\ConnectionActions;
use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Demo\Chat\Utils\ChatAttachmentStorage;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;

/**
 * Write operations for connections runtime data (semantic mutations, not raw diffs).
 *
 * Cross-process / truth-source sync uses framework mechanisms; agent logic calls the named methods below.
 * Per-socket file fields are mutated via {@see RuntimeConnection::actions}.
 *
 * @extends RtActions<RuntimeConnection, Connections>
 */
final class ConnectionsActions extends RtActions
{
    /**
     * Register new connection.
     *
     * @throws RtActionsCallbackNotSetException If callback for creating Rt item is not set
     */
    public function register(string $acceptKey, int $userId): RuntimeConnection
    {
        $state = StateConnection::create($acceptKey, $userId);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Narrows parent return type to this collection's RtItem ({@see RuntimeConnection}).
     */
    protected function createRtItemFromState(RtState &$state): RuntimeConnection
    {
        $item = parent::createRtItemFromState($state);
        if (!$item instanceof RuntimeConnection) {
            throw new \LogicException('Connections item factory must return ' . RuntimeConnection::class);
        }

        return $item;
    }

    /**
     * Unregister connection by accept key (deletes active quarantine part file if any).
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function unregister(string $acceptKey): void
    {
        $this->ensureCanWrite();
        $conn = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($conn !== null && $conn->fileSessionUploadId !== null) {
            $path = ChatAttachmentStorage::quarantinePathForBasename($conn->fileSessionQuarantineBasename);
            ChatAttachmentStorage::deleteIfExists($path);
        }
        $this->removeStateFromCollection($acceptKey);
    }

    /**
     * Clear all connections from the collection.
     */
    public function clear(): void
    {
        $this->clearAllStates();
    }

    /**
     * User dismissed rejected-file banner (client FILE_MODERATION_DISMISS).
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function clearFileModerationBannerAfterDismiss(string $acceptKey): void
    {
        $this->withConnectionActions($acceptKey, static fn ($a) => $a->clearFileModerationBanner());
    }

    /**
     * After appending a binary chunk: update received byte counters and progress UI bytes.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function applyStoredBinaryChunkProgress(string $acceptKey, int $newReceivedBytes): void
    {
        $this->withConnectionActions(
            $acceptKey,
            static fn ($a) => $a->applyStoredBinaryChunkProgress($newReceivedBytes),
        );
    }

    /**
     * When upload bytes are complete: drop session + progress bar state before moderation UI.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function clearFileUploadSessionAfterReceiveComplete(string $acceptKey): void
    {
        $this->withConnectionActions(
            $acceptKey,
            static fn ($a) => $a->clearBinaryUploadSessionAndProgressUi(),
        );
    }

    /**
     * Show "moderating" file banner while ModeratorAgent runs.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function enterFileModerationPending(
        string $acceptKey,
        string $originalFilename,
        int $sizeBytes,
    ): void {
        $this->withConnectionActions(
            $acceptKey,
            static fn ($a) => $a->enterFileModerationPending($originalFilename, $sizeBytes),
        );
    }

    /**
     * Moderator denied the file: show rejected banner on this socket.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function markFileModerationRejected(
        string $acceptKey,
        string $originalFilename,
        int $sizeBytes,
        string $reason,
    ): void {
        $this->withConnectionActions(
            $acceptKey,
            static fn ($a) => $a->markFileModerationRejected($originalFilename, $sizeBytes, $reason),
        );
    }

    /**
     * Allow path but quarantine file missing: clear moderation UI.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function clearFileModerationBannerAfterAllowMissingQuarantine(string $acceptKey): void
    {
        $this->withConnectionActions($acceptKey, static fn ($a) => $a->clearFileModerationBanner());
    }

    /**
     * Move to published failed: clear moderation UI.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function clearFileModerationBannerAfterPublishFailed(string $acceptKey): void
    {
        $this->withConnectionActions($acceptKey, static fn ($a) => $a->clearFileModerationBanner());
    }

    /**
     * File published: clear moderation UI on the initiating socket.
     *
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function clearFileModerationBannerAfterPublishSuccess(string $acceptKey): void
    {
        $this->withConnectionActions($acceptKey, static fn ($a) => $a->clearFileModerationBanner());
    }

    /**
     * Clear file session, moderation UI, and upload progress on every connection (e.g. disk wipe).
     *
     * @throws RtActionsCollectionNameNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function clearAllFileRuntimeOnAllConnections(): void
    {
        $this->ensureCanWrite();
        foreach ($this->collection as $connection) {
            $connection->actions->clearAllFileRuntimeOnSocket();
        }
    }

    /**
     * @param callable(ConnectionActions): void $fn
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    private function withConnectionActions(string $acceptKey, callable $fn): void
    {
        $this->ensureCanWrite();
        if (!isset($this->collection[$acceptKey])) {
            return;
        }
        $item = $this->collection[$acceptKey];
        if (!$item instanceof RuntimeConnection) {
            return;
        }
        $fn($item->actions);
    }
}

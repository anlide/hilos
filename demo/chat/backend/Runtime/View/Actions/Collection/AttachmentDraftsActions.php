<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Database\DTO\PublishedAttachmentInput;
use Demo\Chat\Database\DTO\PublishedAttachmentInputs;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Collection\AttachmentDrafts as StateAttachmentDrafts;
use Demo\Chat\Runtime\State\Item\AttachmentDraft as StateAttachmentDraft;
use Demo\Chat\Runtime\View\Collection\AttachmentDrafts;
use Demo\Chat\Runtime\View\Item\AttachmentDraft;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Fs\FsException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use LogicException;

/**
 * Write API for uploaded attachment drafts.
 *
 * @extends RtActions<AttachmentDraft, AttachmentDrafts, StateAttachmentDrafts>
 * @property-read StateAttachmentDrafts $stateCollection
 */
final class AttachmentDraftsActions extends RtActions
{
    /**
     * Drafts expire one hour after upload completes.
     */
    public const int DRAFT_TTL_SECONDS = 3600;

    /**
     * Create a draft row after a file is moved to quarantine.
     *
     * @return AttachmentDraft Read wrapper around the new draft
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function create(
        string $draftId,
        string $acceptKey,
        int $userId,
        string $quarantineBasename,
        string $originalFilename,
        string $mimeType,
        int $size,
        string $normalizedFilename,
        int $uploadedAt,
    ): AttachmentDraft {
        $state = StateAttachmentDraft::create(
            $draftId,
            $acceptKey,
            $userId,
            $quarantineBasename,
            $originalFilename,
            $mimeType,
            $size,
            $normalizedFilename,
            $uploadedAt,
        );
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Delete every draft owned by a connection.
     *
     * @param string $acceptKey WebSocket connection id
     * @param bool $deleteFiles When true, delete quarantine files as well
     * @return bool True when at least one draft was removed
     * @throws FileDeleteException When a quarantine file cannot be deleted
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function deleteForAcceptKey(string $acceptKey, bool $deleteFiles): bool
    {
        $draftIds = [];
        foreach ($this->collection as $draft) {
            if ($draft->acceptKey === $acceptKey) {
                $draftIds[] = $draft->draftId;
            }
        }

        return $this->deleteByIds($draftIds, $deleteFiles);
    }

    /**
     * Delete every draft owned by a connection and remove its quarantine files.
     *
     * @param string $acceptKey WebSocket connection id
     * @return bool True when at least one draft was removed
     * @throws FileDeleteException When a quarantine file cannot be deleted
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function deleteForAcceptKeyWithFiles(string $acceptKey): bool
    {
        return $this->deleteForAcceptKey($acceptKey, deleteFiles: true);
    }

    /**
     * Delete every draft in this collection.
     *
     * @param bool $deleteFiles When true, delete quarantine files as well
     * @return bool True when at least one draft was removed
     * @throws FileDeleteException When a quarantine file cannot be deleted
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function deleteAll(bool $deleteFiles): bool
    {
        $draftIds = [];
        foreach ($this->collection as $draft) {
            $draftIds[] = $draft->draftId;
        }

        return Hilos::$rt->attachmentDrafts->actions->deleteByIds($draftIds, $deleteFiles);
    }

    /**
     * Delete every draft in this collection and remove its quarantine files.
     *
     * @return bool True when at least one draft was removed
     * @throws FileDeleteException When a quarantine file cannot be deleted
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function deleteAllWithFiles(): bool
    {
        return $this->deleteAll(deleteFiles: true);
    }

    /**
     * Delete requested drafts.
     *
     * @param list<string> $draftIds Draft ids
     * @param bool $deleteFiles When true, delete quarantine files as well
     * @return bool True when at least one draft was removed
     * @throws FileDeleteException When a quarantine file cannot be deleted
     * @throws RtActionsCollectionNameNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function deleteByIds(array $draftIds, bool $deleteFiles): bool
    {
        $this->ensureCanWrite();
        $removed = false;

        foreach ($draftIds as $draftId) {
            $draft = $this->stateCollection->get($draftId);
            if ($draft === null) {
                continue;
            }
            if ($deleteFiles && $draft->quarantineBasename !== '') {
                $file = Hilos::$fs->quarantine[$draft->quarantineBasename];
                if ($file->exists()) {
                    $file->unlink();
                }
            }
            $this->removeStateFromCollection($draftId);
            $removed = true;
        }

        return $removed;
    }

    /**
     * Remove drafts older than the configured TTL.
     *
     * @return list<string> Connection ids whose draft list changed
     * @throws FileDeleteException When a quarantine file cannot be deleted
     * @throws RtActionsCollectionNameNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function deleteExpired(): array
    {
        $draftIds = [];
        $affectedAcceptKeys = [];
        $now = time();
        foreach ($this->collection as $draft) {
            if ($draft->uploadedAt + self::DRAFT_TTL_SECONDS > $now) {
                continue;
            }
            $draftIds[] = $draft->draftId;
            $affectedAcceptKeys[$draft->acceptKey] = true;
        }

        $this->deleteByIds($draftIds, deleteFiles: true);

        return array_keys($affectedAcceptKeys);
    }

    /**
     * Move a connection's completed drafts to published storage and clear their runtime rows.
     *
     * Returns null when a quarantine file disappeared before any publish move starts.
     *
     * @param Connection $connection Connection whose drafts should be published
     * @return ?PublishedAttachmentInputs Published attachment metadata, or null on missing draft file
     * @throws FsException When a quarantine file cannot be moved
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws RtActionsStateCollectionNullException
     */
    public function publishForConnection(Connection $connection): ?PublishedAttachmentInputs
    {
        $this->ensureCanWrite();

        $draftIds = [];
        $attachments = [];
        foreach ($connection->attachmentDrafts as $draft) {
            $quarantineFile = Hilos::$fs->quarantine[$draft->quarantineBasename];
            if (!$quarantineFile->exists()) {
                Hilos::$rt->attachmentDrafts[$draft->draftId]?->actions->delete(deleteFiles: false);
                return null;
            }

            $draftIds[] = $draft->draftId;
            $attachments[] = new PublishedAttachmentInput(
                filename: $draft->originalFilename,
                mimeType: $draft->mimeType,
                storedName: $draft->quarantineBasename,
            );
        }

        foreach ($connection->attachmentDrafts as $draft) {
            Hilos::$fs->quarantine[$draft->quarantineBasename]->move('published');
        }

        $this->deleteByIds($draftIds, deleteFiles: false);

        return new PublishedAttachmentInputs(...$attachments);
    }

    /**
     * Remove every draft and optionally delete all corresponding quarantine files.
     *
     * @throws FileDeleteException When a quarantine file cannot be deleted
     * @throws RtActionsCollectionNameNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function clear(bool $deleteFiles): void
    {
        $draftIds = [];
        foreach ($this->collection as $draft) {
            $draftIds[] = $draft->draftId;
        }
        $this->deleteByIds($draftIds, $deleteFiles);
    }

    /**
     * Remove every draft and delete all corresponding quarantine files.
     *
     * @throws FileDeleteException When a quarantine file cannot be deleted
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function clearWithFiles(): void
    {
        $this->clear(deleteFiles: true);
    }

    /**
     * Narrows parent return type to this collection's RtItem.
     *
     * @param RtState $state State instance
     * @return AttachmentDraft
     * @throws RtActionsCallbackNotSetException
     */
    protected function createRtItemFromState(RtState &$state): AttachmentDraft
    {
        $item = parent::createRtItemFromState($state);
        if (!$item instanceof AttachmentDraft) {
            throw new LogicException('AttachmentDrafts item factory must return ' . AttachmentDraft::class);
        }

        return $item;
    }
}

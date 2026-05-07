<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Collection\AttachmentDrafts as StateAttachmentDrafts;
use Demo\Chat\Runtime\State\Item\AttachmentDraft as StateAttachmentDraft;
use Demo\Chat\Runtime\View\Collection\AttachmentDrafts;
use Demo\Chat\Runtime\View\Item\AttachmentDraft;
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

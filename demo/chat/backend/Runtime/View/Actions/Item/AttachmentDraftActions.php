<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\AttachmentDraft as StateAttachmentDraft;
use Demo\Chat\Runtime\View\Item\AttachmentDraft;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

/**
 * Write operations for one uploaded attachment draft.
 *
 * @extends RtActions<AttachmentDraft, StateAttachmentDraft>
 * @property-read StateAttachmentDraft $state
 */
final class AttachmentDraftActions extends RtActions
{
    /**
     * Delete this draft and optionally its quarantine file.
     *
     * @param bool $deleteFiles When true, delete the quarantine file as well
     *
     * @throws FileDeleteException When a quarantine file cannot be deleted
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     */
    public function delete(bool $deleteFiles): void
    {
        if ($deleteFiles && $this->state->quarantineBasename !== '') {
            $file = Hilos::$fs->quarantine[$this->state->quarantineBasename];
            if ($file->exists()) {
                $file->unlink();
            }
        }

        $this->remove();
    }
}

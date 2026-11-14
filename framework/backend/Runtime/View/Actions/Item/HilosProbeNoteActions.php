<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosProbeNote as StateHilosProbeNote;
use Hilos\Runtime\View\Item\HilosProbeNote as ViewHilosProbeNote;
use Hilos\HilosException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Core\Exception\InvalidArgumentException;

/**
 * Write operations for one note of the set probe.
 *
 * @extends RtActions<ViewHilosProbeNote, StateHilosProbeNote>
 * @property-read StateHilosProbeNote $state
 */
final class HilosProbeNoteActions extends RtActions
{
    /**
     * Rewrites the text of this note.
     *
     * The note stays in its set: moving it into another set is the right of the owner of the
     * whole collection (HIL-1115), and the stand has none.
     *
     * @param string $text New text of the note
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not hold the set the note is in
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function rewrite(string $text): void
    {
        $this->ensureCanWrite();

        $this->applyDiffWithSync([
            StateHilosProbeNote::text => $text,
        ]);
    }

    /**
     * Removes this note through the truth-source door of its set.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws HilosException Whatever preparing the RT write's immutable operation raises
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws RtItemParentCollectionNullException When the note is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When this node does not hold the note's set
     * @throws SourceChangeSubscriberException When a subscriber rejects the removal
     */
    public function erase(): void
    {
        $this->remove();
    }
}

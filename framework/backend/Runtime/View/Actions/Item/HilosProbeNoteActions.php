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
     */
    public function rewrite(string $text): void
    {
        $this->ensureCanWrite();

        $this->state->text = $text;

        $this->sync();
    }

    /**
     * Removes this note through the truth-source door of its set.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When the note is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When this node does not hold the note's set
     * @throws SourceChangeSubscriberException When a subscriber rejects the removal
     */
    public function erase(): void
    {
        $this->remove();
    }
}

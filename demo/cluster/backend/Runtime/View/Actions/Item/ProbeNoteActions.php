<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Actions\Item;

use Demo\Cluster\Runtime\State\Item\ProbeNote as StateProbeNote;
use Demo\Cluster\Runtime\View\Item\ProbeNote as ViewProbeNote;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

/**
 * Write operations for one note of the set probe.
 *
 * @extends RtActions<ViewProbeNote, StateProbeNote>
 * @property-read StateProbeNote $state
 */
final class ProbeNoteActions extends RtActions
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
}

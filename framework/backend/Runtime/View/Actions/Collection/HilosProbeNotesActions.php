<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Runtime\State\Collection\HilosProbeNotes as StateHilosProbeNotes;
use Hilos\Runtime\State\Item\HilosProbeNote as StateHilosProbeNote;
use Hilos\Runtime\View\Collection\HilosProbeNotes;
use Hilos\Runtime\View\Item\HilosProbeNote as ViewHilosProbeNote;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;

/**
 * Write API for the notes of the set probe.
 *
 * @extends RtActions<ViewHilosProbeNote, HilosProbeNotes, StateHilosProbeNotes>
 * @property-read StateHilosProbeNotes $stateCollection
 */
final class HilosProbeNotesActions extends RtActions
{
    /**
     * Writes one note: creates it in the named set, or rewrites the text of the one standing.
     *
     * No check of its own before either road, unlike a writer that knows it owns its row: the
     * probe exists to show the truth-source door refusing, so each road goes straight through
     * its door and the door judges the write by the set the row is in - the creation door by the
     * set the new row is born in (HIL-1115), the row door by the set it stands in.
     *
     * @param string $noteId Note id, and the row id
     * @param string $nodeId Node whose set a new note is born in; a standing note keeps its own
     * @param string $text Text of the note
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtActionsItemClassException When the runtime item class is missing or invalid
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not hold the set the note is in
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function write(string $noteId, string $nodeId, string $text): void
    {
        $existing = $this->collection[$noteId] ?? null;
        if ($existing instanceof ViewHilosProbeNote) {
            $existing->actions->rewrite($text);

            return;
        }

        $this->addStateToCollection(StateHilosProbeNote::create($noteId, $nodeId, $text));
    }
}

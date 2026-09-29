<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Runtime\State\Collection\HilosProbeNotes as StateHilosProbeNotes;
use Hilos\Runtime\State\Item\HilosProbeNote as StateHilosProbeNote;
use Hilos\Runtime\View\Actions\Collection\HilosProbeNotesActions;
use Hilos\Runtime\View\Item\HilosProbeNote;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\RtState;

/**
 * Read-only wrapper around the notes of the set probe.
 *
 * @extends RtCollection<HilosProbeNote, HilosProbeNotesActions>
 * @property-read HilosProbeNotesActions $actions Actions for write operations
 */
final class HilosProbeNotes extends RtCollection
{
    /**
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosProbeNotes
    {
        /** @var StateHilosProbeNotes */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateHilosProbeNote instance
     * @return HilosProbeNote View item for this note
     */
    protected function createRtItem(RtState $state): HilosProbeNote
    {
        /** @var StateHilosProbeNote $state */
        return new HilosProbeNote($state);
    }

    /**
     * @param mixed $offset Note id
     * @return ?HilosProbeNote Item or null
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosProbeNote
    {
        /** @var ?HilosProbeNote $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosProbeNotesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosProbeNotesActions
    {
        /** @var HilosProbeNotesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever reading another declared property of the parent raises
     */
    public function __get(string $name): HilosProbeNotesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}

<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Collection;

use Demo\Cluster\Runtime\State\Collection\ProbeNotes as StateProbeNotes;
use Demo\Cluster\Runtime\State\Item\ProbeNote as StateProbeNote;
use Demo\Cluster\Runtime\View\Actions\Collection\ProbeNotesActions;
use Demo\Cluster\Runtime\View\Item\ProbeNote;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;

/**
 * Read-only wrapper around the notes of the set probe.
 *
 * @extends RtCollection<ProbeNote, ProbeNotesActions>
 * @property-read ProbeNotesActions $actions Actions for write operations
 */
final class ProbeNotes extends RtCollection
{
    /**
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateProbeNotes
    {
        /** @var StateProbeNotes */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateProbeNote instance
     * @return ProbeNote View item for this note
     */
    protected function createRtItem(RtState $state): ProbeNote
    {
        /** @var StateProbeNote $state */
        return new ProbeNote($state);
    }

    /**
     * @param mixed $offset Note id
     * @return ?ProbeNote Item or null
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?ProbeNote
    {
        /** @var ?ProbeNote $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return ProbeNotesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): ProbeNotesActions
    {
        /** @var ProbeNotesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever reading another declared property of the parent raises
     */
    public function __get(string $name): ProbeNotesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}

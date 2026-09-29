<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\State\Item\HilosProbeNote as StateHilosProbeNote;
use Hilos\Runtime\View\Actions\Item\HilosProbeNoteActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\HilosException;

/**
 * Read-only wrapper over one note of the set probe.
 *
 * @extends RtItem<StateHilosProbeNote>
 *
 * @property-read string $noteId Note id
 * @property-read string $nodeId Node whose set the note is in
 * @property-read string $text Text of the note
 * @property-read HilosProbeNoteActions $actions Write operations for this note
 */
final class HilosProbeNote extends RtItem
{
    /**
     * @param StateHilosProbeNote $state Backing runtime state
     */
    public function __construct(StateHilosProbeNote $state)
    {
        parent::__construct($state);
    }

    /**
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws HilosException When an inherited getter or an implementation's relation read fails
     */
    public function __get(string $name): string|HilosProbeNoteActions|null
    {
        return match ($name) {
            StateHilosProbeNote::noteId => $this->_state->noteId,
            StateHilosProbeNote::nodeId => $this->_state->nodeId,
            StateHilosProbeNote::text => $this->_state->text,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

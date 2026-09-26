<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Item;

use Demo\Cluster\Runtime\State\Item\ProbeNote as StateProbeNote;
use Demo\Cluster\Runtime\View\Actions\Item\ProbeNoteActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only wrapper over one note of the set probe.
 *
 * @extends RtItem<StateProbeNote>
 *
 * @property-read string $noteId Note id
 * @property-read string $nodeId Node whose set the note is in
 * @property-read string $text Text of the note
 * @property-read ProbeNoteActions $actions Write operations for this note
 */
final class ProbeNote extends RtItem
{
    /**
     * @param StateProbeNote $state Backing runtime state
     */
    public function __construct(StateProbeNote $state)
    {
        parent::__construct($state);
    }

    /**
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|ProbeNoteActions|null
    {
        return match ($name) {
            StateProbeNote::noteId => $this->_state->noteId,
            StateProbeNote::nodeId => $this->_state->nodeId,
            StateProbeNote::text => $this->_state->text,
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

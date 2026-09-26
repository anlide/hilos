<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\State\Collection;

use Demo\Cluster\Runtime\State\Item\ProbeNote;
use Hilos\Runtime\State\Collection\RtStates;
use OutOfBoundsException;

/**
 * Runtime notes of the set probe keyed by note id.
 *
 * @extends RtStates<ProbeNote>
 */
final class ProbeNotes extends RtStates
{
    public const string STATE_CLASS = ProbeNote::class;

    /**
     * @param ?string $id Note id, or null for a missing optional runtime key
     * @return ?ProbeNote Note, or null when missing
     */
    public function get(?string $id): ?ProbeNote
    {
        /** @var ?ProbeNote $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid.
     *
     * @param mixed $offset Note id
     * @return ProbeNote Note
     * @throws OutOfBoundsException When no row stands under that id
     */
    public function offsetGet(mixed $offset): ProbeNote
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Probe note not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Probe note not found: {$offset}");
    }
}

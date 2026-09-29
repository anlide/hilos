<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosProbeNote;
use OutOfBoundsException;

/**
 * Runtime notes of the set probe keyed by note id.
 *
 * @extends RtStates<HilosProbeNote>
 */
final class HilosProbeNotes extends RtStates
{
    public const string STATE_CLASS = HilosProbeNote::class;

    /**
     * @param ?string $id Note id, or null for a missing optional runtime key
     * @return ?HilosProbeNote Note, or null when missing
     */
    public function get(?string $id): ?HilosProbeNote
    {
        /** @var ?HilosProbeNote $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid.
     *
     * @param mixed $offset Note id
     * @return HilosProbeNote Note
     * @throws OutOfBoundsException When no row stands under that id
     */
    public function offsetGet(mixed $offset): HilosProbeNote
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Probe note not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Probe note not found: {$offset}");
    }
}

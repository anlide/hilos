<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use OutOfBoundsException;

/**
 * Runtime probe fleet statuses keyed by fleet member index.
 *
 * @extends RtStates<HilosProbeFleetStatus>
 */
final class HilosProbeFleetStatuses extends RtStates
{
    public const string STATE_CLASS = HilosProbeFleetStatus::class;

    /**
     * @param ?string $id Fleet member index, or null for a missing optional runtime key
     * @return ?HilosProbeFleetStatus Worker status, or null when missing
     */
    public function get(?string $id): ?HilosProbeFleetStatus
    {
        /** @var ?HilosProbeFleetStatus $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid.
     *
     * @param mixed $offset Fleet member index
     * @return HilosProbeFleetStatus Worker status
     * @throws OutOfBoundsException When no row stands under that index
     */
    public function offsetGet(mixed $offset): HilosProbeFleetStatus
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Probe fleet status not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Probe fleet status not found: {$offset}");
    }
}

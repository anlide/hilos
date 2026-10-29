<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosStatusMaintenance as StateHilosStatusMaintenance;
use OutOfBoundsException;

/**
 * Runtime Status maintenance facts keyed by singleton key.
 *
 * @extends RtStates<StateHilosStatusMaintenance>
 */
final class HilosStatusMaintenance extends RtStates
{
    public const string STATE_CLASS = StateHilosStatusMaintenance::class;

    /**
     * @param ?string $id Singleton key, or null for a missing optional runtime key
     * @return ?StateHilosStatusMaintenance Maintenance facts, or null when missing
     */
    public function get(?string $id): ?StateHilosStatusMaintenance
    {
        /** @var ?StateHilosStatusMaintenance $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid.
     *
     * @param mixed $offset Singleton key
     * @return StateHilosStatusMaintenance Maintenance facts
     * @throws OutOfBoundsException When no row stands under that index
     */
    public function offsetGet(mixed $offset): StateHilosStatusMaintenance
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Status maintenance observation not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Status maintenance observation not found: {$offset}");
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosStatusComponent;
use OutOfBoundsException;

/**
 * Runtime Status component counts keyed by component key.
 *
 * @extends RtStates<HilosStatusComponent>
 */
final class HilosStatusComponents extends RtStates
{
    public const string STATE_CLASS = HilosStatusComponent::class;

    /**
     * @param ?string $id Component key, or null for a missing optional runtime key
     * @return ?HilosStatusComponent Component counts, or null when missing
     */
    public function get(?string $id): ?HilosStatusComponent
    {
        /** @var ?HilosStatusComponent $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid.
     *
     * @param mixed $offset Component key
     * @return HilosStatusComponent Component counts
     * @throws OutOfBoundsException When no row stands under that index
     */
    public function offsetGet(mixed $offset): HilosStatusComponent
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Status component count not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Status component count not found: {$offset}");
    }
}

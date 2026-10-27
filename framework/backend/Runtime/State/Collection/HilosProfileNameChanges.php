<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosProfileNameChange;
use OutOfBoundsException;

/**
 * Name-change attempts keyed by the hash of the session cookie token, mounted only by AUTH.
 *
 * @extends RtStates<HilosProfileNameChange>
 */
final class HilosProfileNameChanges extends RtStates
{
    public const string STATE_CLASS = HilosProfileNameChange::class;

    /**
     * @param ?string $id Session token hash, or null for a missing optional key
     * @return ?HilosProfileNameChange Attempt, or null when this session has none
     */
    public function get(?string $id): ?HilosProfileNameChange
    {
        /** @var ?HilosProfileNameChange $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * @param mixed $offset Session token hash
     * @return HilosProfileNameChange Name-change attempt
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): HilosProfileNameChange
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Profile name change not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Profile name change not found: {$offset}");
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Runtime\State\Item\HilosOAuthTrip;
use OutOfBoundsException;

/**
 * HilosOAuthTrips - the provider sign-ins browser tabs are waiting on (HIL-1044).
 *
 * Framework-owned state collection mounted by {@see AuthFeature::mount()} and by nothing else:
 * a project with no sign-in surface starts no trips, and a collection mounted for it would be
 * read on every tick and never written.
 *
 * A row appears when a callback is accepted and goes once it has an ending nobody needs any
 * more; a row with no ending is never reclaimed by time, only by a fact. The collection is the
 * size of the exchanges in flight plus the endings of the last few minutes.
 *
 * @extends RtStates<HilosOAuthTrip>
 */
final class HilosOAuthTrips extends RtStates
{
    public const string STATE_CLASS = HilosOAuthTrip::class;

    /**
     * @param ?string $tripKeyHash Hash of a trip key, or null for a missing optional key
     * @return ?HilosOAuthTrip Trip row, or null when no trip is kept under that hash
     */
    public function get(?string $tripKeyHash): ?HilosOAuthTrip
    {
        /** @var ?HilosOAuthTrip $state */
        $state = parent::get($tripKeyHash);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid - and here it
     * usually is, because a key a tab presents may name a trip that was reclaimed long ago.
     *
     * @param mixed $offset Hash of a trip key
     * @return HilosOAuthTrip Trip row
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): HilosOAuthTrip
    {
        if ($offset === null) {
            throw new OutOfBoundsException('OAuth trip not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("OAuth trip not found: {$offset}");
    }
}

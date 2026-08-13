<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosSessionRotation;
use OutOfBoundsException;

/**
 * HilosSessionRotations - pending token rotations keyed by their one-time ticket (HIL-582).
 *
 * Framework-owned state collection mounted for every project: the agent owning the session
 * seam writes it in a worker, and the master reads it on the handshake that trades a ticket
 * for the rotated token. A project that carries no sessions never registers a truth source
 * for it, so the collection simply stays empty.
 *
 * @extends RtStates<HilosSessionRotation>
 */
final class HilosSessionRotations extends RtStates
{
    public const string STATE_CLASS = HilosSessionRotation::class;

    /**
     * @param ?string $ticket One-time rotation ticket, or null for a missing optional key
     * @return ?HilosSessionRotation Rotation row, or null when missing
     */
    public function get(?string $ticket): ?HilosSessionRotation
    {
        /** @var ?HilosSessionRotation $state */
        $state = parent::get($ticket);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid - and here it
     * usually is, because an unknown ticket is the normal answer for anyone who did not
     * just log in.
     *
     * @param mixed $offset One-time rotation ticket
     * @return HilosSessionRotation Rotation row
     */
    public function offsetGet(mixed $offset): HilosSessionRotation
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Session rotation not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Session rotation not found: {$offset}");
    }
}

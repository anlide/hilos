<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\HilosSessionToastStack;
use OutOfBoundsException;

/**
 * HilosSessionToastStacks - the live toast stacks, one row per browser session (HIL-768).
 *
 * Framework-owned state collection mounted for every project: the agent owning the session
 * seam writes it, and every tab of a session is shown what its row says. A project that
 * carries no sessions never registers a truth source for it, so the collection simply stays
 * empty.
 *
 * It holds only what somebody is still being shown: a row appears when a card is raised for a
 * session with a live socket and is taken away the moment its list empties, so the collection
 * is the size of the toasts on screen right now rather than of the sessions that exist.
 *
 * @extends RtStates<HilosSessionToastStack>
 */
final class HilosSessionToastStacks extends RtStates
{
    public const string STATE_CLASS = HilosSessionToastStack::class;

    /**
     * @param ?string $sessionTokenHash Hash of a session cookie token, or null for a missing optional key
     * @return ?HilosSessionToastStack Stack row, or null when the session is owed nothing
     */
    public function get(?string $sessionTokenHash): ?HilosSessionToastStack
    {
        /** @var ?HilosSessionToastStack $state */
        $state = parent::get($sessionTokenHash);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid - and here it
     * almost always is, because a session owed nothing has no row at all.
     *
     * @param mixed $offset Hash of a session cookie token
     * @return HilosSessionToastStack Stack row
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): HilosSessionToastStack
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Session toast stack not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Session toast stack not found: {$offset}");
    }
}

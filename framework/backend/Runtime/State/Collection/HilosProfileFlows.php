<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Runtime\State\Item\HilosProfileFlow;
use OutOfBoundsException;

/**
 * HilosProfileFlows - the profile windows somebody is half-way through, one row per session and
 * window (HIL-1182).
 *
 * Framework-owned state collection mounted by {@see AuthFeature::mount()} and by nothing else: the
 * windows it remembers are the sign-in surface's own, and a project without one has no flow to
 * remember.
 *
 * It holds only the flows still going: a row appears with the first code a window sends and goes
 * when the flow is finished, discarded, outlived by its code or left behind by a change of person,
 * so the collection is the size of the windows open right now rather than of the sessions that exist.
 *
 * @extends RtStates<HilosProfileFlow>
 */
final class HilosProfileFlows extends RtStates
{
    public const string STATE_CLASS = HilosProfileFlow::class;

    /**
     * @param ?string $id Row id ({@see HilosProfileFlow::idFor()}), or null for a missing optional key
     * @return ?HilosProfileFlow Flow row, or null when the window has no flow in this session
     */
    public function get(?string $id): ?HilosProfileFlow
    {
        /** @var ?HilosProfileFlow $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid - and here it usually
     * is, because a window nobody has sent a code from has no row at all.
     *
     * @param mixed $offset Row id ({@see HilosProfileFlow::idFor()})
     * @return HilosProfileFlow Flow row
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): HilosProfileFlow
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Profile flow not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Profile flow not found: {$offset}");
    }
}

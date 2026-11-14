<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use LogicException;
use WeakMap;

/**
 * @internal The shared resolution root of one collection's row handles: the values and baselines a
 * published snapshot generation gave rows that kept their identity, read through each handle until
 * it is settled. Publication installs a whole prepared generation in one assignment, so no row is
 * visited when it becomes visible; settling then moves it into the handles in portions, and a write
 * to a handle settles that handle first. Process-local copy machinery of one collection, not a
 * replicated collection of Hilos::$rt (docs/agents/runtime/rt-context.md).
 */
final class RtRowRoot
{
    /** @var WeakMap<RtRowBinding, array{RtRowDescriptor, RtRowDescriptor}> Values and baseline per handle, not settled yet */
    private WeakMap $published;

    public function __construct()
    {
        $this->published = new WeakMap();
    }

    /**
     * Makes a prepared generation the one the handles read, in constant work.
     *
     * @param WeakMap<RtRowBinding, array{RtRowDescriptor, RtRowDescriptor}> $generation Values and baseline per handle
     * @throws LogicException When the previous generation is not settled yet
     */
    public function install(WeakMap $generation): void
    {
        if (count($this->published) > 0) {
            throw new LogicException('An RT row generation is installed over one that is not settled yet');
        }
        $this->published = $generation;
    }

    /**
     * @param RtRowBinding $binding Handle to read
     * @return ?array{RtRowDescriptor, RtRowDescriptor} Values and baseline published for it, null when none waits
     */
    public function staged(RtRowBinding $binding): ?array
    {
        return $this->published[$binding] ?? null;
    }

    /**
     * Hands one handle what was published for it and forgets it here.
     *
     * @param RtRowBinding $binding Handle being settled
     * @return ?array{RtRowDescriptor, RtRowDescriptor} Values and baseline published for it, null when none waits
     */
    public function take(RtRowBinding $binding): ?array
    {
        $pair = $this->published[$binding] ?? null;
        unset($this->published[$binding]);

        return $pair;
    }

    /** @return bool Whether every handle of the installed generation holds its values itself */
    public function isSettled(): bool
    {
        return count($this->published) === 0;
    }

    /**
     * Settles one handle of the installed generation, in constant work.
     */
    public function settleOne(): void
    {
        foreach ($this->published as $binding => $_) {
            $binding->settle();

            return;
        }
    }
}

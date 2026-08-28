<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Runtime\State\Collection;

use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;

/**
 * Deliberately broken sample of the second road into the same store: a concrete state
 * collection that writes its inherited row array directly, so the membership changes
 * with nothing announced. Every write below must be reported by RT-STATE-MUTATE.
 *
 * @extends RtStates<RtState>
 */
final class StateMutateSubclass extends RtStates
{
    /**
     * @param string $id Key written, dropped, wiped along with the rest and coalesce-assigned
     * @param RtState $state Row put under that key
     */
    public function rewrite(string $id, RtState $state): void
    {
        $this->states[$id] = $state;
        unset($this->states[$id]);
        $this->states = [];
        $this->states[$id] ??= $state;
        $this->states ??= [];
    }

    /**
     * A read of the same array, which the rule leaves alone: a narrowed lookup is how a
     * concrete collection is expected to be written, and reading desynchronizes nobody.
     *
     * @param string $id Key to look up
     * @return array<string, RtState> Rows under every key but that one
     */
    public function without(string $id): array
    {
        return array_filter(
            $this->states,
            static fn(string $key): bool => $key !== $id,
            ARRAY_FILTER_USE_KEY,
        );
    }
}

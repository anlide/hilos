<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Runtime\State\Collection;

use Hilos\Runtime\State\Item\RtState;

/**
 * Negative sample: the same writes of the row array, in the file that declares it. The
 * store owns its own storage — a rule that fired here would leave `add()`, `remove()`
 * and `clear()` with no way to do their work — so this file must report nothing.
 */
final class RtStates
{
    /** @var array<string, RtState> Rows this collection holds, keyed by state id */
    private array $states = [];

    /**
     * @param string $id Key written, dropped and then wiped along with the rest
     * @param RtState $state Row put under that key
     */
    public function rewrite(string $id, RtState $state): void
    {
        $this->states[$id] = $state;
        unset($this->states[$id]);
        $this->states = [];
    }
}

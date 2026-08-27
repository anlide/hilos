<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Runtime\View\Collection;

use Closure;
use Hilos\Runtime\State\Item\RtState;

/**
 * Deliberately broken sample: a runtime view collection whose item factory takes the
 * state by reference, which is how the trap spread — the signature is what an author
 * copies when writing a factory of their own. The closure below is the same factory
 * handed over as a callback, and the backing collection is bound by reference too, so
 * every line here must be reported by VIEW-WRAPPER-BIND.
 */
final class ByRefFactorySamples
{
    /** @var array<string, RtState> Backing rows, bound to the variable they arrived in */
    private array $_stateCollection = [];

    /**
     * @param array<string, RtState> $stateCollection Rows the collection reads
     */
    public function setStateCollection(array &$stateCollection): void
    {
        $this->_stateCollection = &$stateCollection;
    }

    /**
     * @param RtState $state Row the item is built from
     * @return string Whatever stands for an item in a fixture
     */
    public function createRtItem(RtState &$state): string
    {
        return $state->getId();
    }

    /**
     * The same factory handed over as a callback: a closure declares its parameters the
     * way a method does, and the rule reads both.
     *
     * @return Closure Factory the collection would register on its actions
     */
    public function factory(): Closure
    {
        return function (RtState &$state): string {
            return $state->getId();
        };
    }
}

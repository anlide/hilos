<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Hilos\Runtime\View\Context\RtContext;

/**
 * Deliberately broken sample: this file sits outside Database/ and Runtime/, so
 * every backing-state reach below must be reported by RT-STATE-REACH.
 */
final class RtStateReach
{
    /**
     * @param object $context Runtime context handed in by the caller
     * @return array<int, mixed> Whatever the backing state gives away
     */
    public function reach(object $context): array
    {
        return [
            $context->getStateCollection('userStates'),
            $context->getStateItem('userStates', 'u-1'),
            RtContext::getStateItem('userStates', 'u-2'),
            $this->stateCollection['u-1'],
        ];
    }
}

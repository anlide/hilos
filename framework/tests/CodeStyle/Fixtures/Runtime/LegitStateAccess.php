<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Runtime;

/**
 * Negative sample: the very same reaches, but under a `Runtime/` segment, where
 * backing state belongs. A rule that fires here would ban the owner from its own
 * state.
 */
final class LegitStateAccess
{
    /**
     * @param object $context Runtime context handed in by the caller
     * @return array<int, mixed> Backing state read by its owner
     */
    public function read(object $context): array
    {
        return [
            $context->getStateCollection('userStates'),
            $context->getStateItem('userStates', 'u-1'),
            $this->stateCollection['u-1'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: every call below forks the PHP process, and this file is
 * not in the rule's list, so PROCESS-FORK must report each one of the three — whether
 * the builtin is written bare or fully qualified.
 *
 * The last call is the exception that proves where the family ends: a namespaced function
 * of somebody's own, wearing one of the names. CODE-FQN has something to say about how
 * it is written, and PROCESS-FORK must have nothing to say about it at all. It sits
 * here rather than among the look-alikes because those must be clean for every rule.
 */
final class ProcessForkSamples
{
    /**
     * @return array<int, mixed> Results from forking calls
     */
    public function fork(): array
    {
        return [
            pcntl_fork(),
            \pcntl_fork(),
            pcntl_rfork(),
            \Hilos\Tests\CodeStyle\Fixtures\Bad\Forker\pcntl_fork(),
        ];
    }
}

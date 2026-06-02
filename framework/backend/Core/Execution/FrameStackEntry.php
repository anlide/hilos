<?php

declare(strict_types=1);

namespace Hilos\Core\Execution;

/**
 * One entry on the execution frame stack: an execution frame plus the pop-order
 * token returned when it was pushed.
 */
final class FrameStackEntry
{
    /**
     * @param int $token Stack token that pop() must match to unwind this entry
     * @param ExecutionFrame $frame Frame active while this entry is on top of the stack
     */
    public function __construct(
        public readonly int $token,
        public readonly ExecutionFrame $frame,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Event\TestRunner\ExecutionAborted;
use PHPUnit\Event\TestRunner\ExecutionAbortedSubscriber as PHPUnitExecutionAbortedSubscriber;

final class ExecutionAbortedSubscriber implements PHPUnitExecutionAbortedSubscriber
{
    /**
     * @param TestTimeLimit $timeLimit Alarm shared by the execution subscribers
     */
    public function __construct(private readonly TestTimeLimit $timeLimit)
    {
    }

    /**
     * Releases the process alarm when execution ends.
     *
     * @param ExecutionAborted $event Execution lifecycle event
     */
    public function notify(ExecutionAborted $event): void
    {
        $this->timeLimit->disarm();
    }
}

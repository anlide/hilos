<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as PHPUnitExecutionFinishedSubscriber;

final class ExecutionFinishedSubscriber implements PHPUnitExecutionFinishedSubscriber
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
     * @param ExecutionFinished $event Execution lifecycle event
     */
    public function notify(ExecutionFinished $event): void
    {
        $this->timeLimit->disarm();
    }
}

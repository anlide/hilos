<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber as PHPUnitExecutionStartedSubscriber;

final class ExecutionStartedSubscriber implements PHPUnitExecutionStartedSubscriber
{
    /**
     * @param TestTimeLimit $timeLimit Alarm shared by the execution subscribers
     */
    public function __construct(private readonly TestTimeLimit $timeLimit)
    {
    }

    /**
     * Covers the execution before its first test class starts.
     *
     * @param ExecutionStarted $event Execution lifecycle event
     */
    public function notify(ExecutionStarted $event): void
    {
        $this->timeLimit->arm($event->testSuite()->name());
    }
}

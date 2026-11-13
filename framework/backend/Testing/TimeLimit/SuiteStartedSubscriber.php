<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;

final class SuiteStartedSubscriber implements StartedSubscriber
{
    /**
     * @param TestTimeLimit $timeLimit Alarm shared by the execution subscribers
     */
    public function __construct(private readonly TestTimeLimit $timeLimit)
    {
    }

    /**
     * Covers class setup before PHPUnit invokes its hook.
     *
     * @param Started $event Execution lifecycle event
     */
    public function notify(Started $event): void
    {
        if ($event->testSuite()->isForTestClass()) {
            $this->timeLimit->arm($event->testSuite()->name());
        }
    }
}

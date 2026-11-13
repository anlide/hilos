<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;

final class TestFailedSubscriber implements FailedSubscriber
{
    /**
     * @param TestTimeLimit $timeLimit Alarm shared by the execution subscribers
     */
    public function __construct(private readonly TestTimeLimit $timeLimit)
    {
    }

    /**
     * Records any swallowed timeout and covers the remaining class cleanup.
     *
     * @param Failed $event Execution lifecycle event
     */
    public function notify(Failed $event): void
    {
        $this->timeLimit->finishTest($event->test());
    }
}

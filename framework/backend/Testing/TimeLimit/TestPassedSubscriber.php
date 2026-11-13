<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;

final class TestPassedSubscriber implements PassedSubscriber
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
     * @param Passed $event Execution lifecycle event
     */
    public function notify(Passed $event): void
    {
        $this->timeLimit->finishTest($event->test());
    }
}

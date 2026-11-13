<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;

final class TestMarkedIncompleteSubscriber implements MarkedIncompleteSubscriber
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
     * @param MarkedIncomplete $event Execution lifecycle event
     */
    public function notify(MarkedIncomplete $event): void
    {
        $this->timeLimit->finishTest($event->test());
    }
}

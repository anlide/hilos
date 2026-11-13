<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;

final class TestErroredSubscriber implements ErroredSubscriber
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
     * @param Errored $event Execution lifecycle event
     */
    public function notify(Errored $event): void
    {
        $this->timeLimit->finishTest($event->test(), $event->throwable());
    }
}

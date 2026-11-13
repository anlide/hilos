<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\Test\PreparationFailedSubscriber;

final class TestPreparationFailedSubscriber implements PreparationFailedSubscriber
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
     * @param PreparationFailed $event Execution lifecycle event
     */
    public function notify(PreparationFailed $event): void
    {
        $this->timeLimit->finishTest($event->test());
    }
}

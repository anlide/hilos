<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

final class TestPreparationStartedSubscriber implements PreparationStartedSubscriber
{
    /**
     * @param TestTimeLimit $timeLimit Alarm shared by the execution subscribers
     */
    public function __construct(private readonly TestTimeLimit $timeLimit)
    {
    }

    /**
     * Starts a fresh limit for test preparation and its body.
     *
     * @param PreparationStarted $event Execution lifecycle event
     */
    public function notify(PreparationStarted $event): void
    {
        $this->timeLimit->arm($event->test()->id());
    }
}

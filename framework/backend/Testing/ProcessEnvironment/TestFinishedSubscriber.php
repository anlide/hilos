<?php

declare(strict_types=1);

namespace Hilos\Testing\ProcessEnvironment;

use Hilos\Core\Exception\LogicException;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/** Restores the running test's snapshot when the test finishes. */
final class TestFinishedSubscriber implements FinishedSubscriber
{
    /**
     * @param ProcessEnvironmentSnapshots $snapshots Stack shared by the five subscribers
     */
    public function __construct(private readonly ProcessEnvironmentSnapshots $snapshots)
    {
    }

    /**
     * Closes the test snapshot. Which test finished is not read.
     *
     * @param Finished $event Test that finished (unused)
     * @throws LogicException When the process environment cannot be read
     */
    public function notify(Finished $event): void
    {
        $this->snapshots->closeTest();
    }
}

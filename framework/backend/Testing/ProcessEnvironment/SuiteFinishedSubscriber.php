<?php

declare(strict_types=1);

namespace Hilos\Testing\ProcessEnvironment;

use Hilos\Core\Exception\LogicException;
use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;

/** Restores a suite snapshot when a suite finishes. */
final class SuiteFinishedSubscriber implements FinishedSubscriber
{
    /**
     * @param ProcessEnvironmentSnapshots $snapshots Stack shared by the five subscribers
     */
    public function __construct(private readonly ProcessEnvironmentSnapshots $snapshots)
    {
    }

    /**
     * Closes the suite that just finished. Which suite finished is not read.
     *
     * @param Finished $event Suite that finished (unused)
     * @throws LogicException When the process environment cannot be read
     */
    public function notify(Finished $event): void
    {
        $this->snapshots->closeSuite();
    }
}

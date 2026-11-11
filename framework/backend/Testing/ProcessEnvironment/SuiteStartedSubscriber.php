<?php

declare(strict_types=1);

namespace Hilos\Testing\ProcessEnvironment;

use Hilos\Core\Exception\LogicException;
use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;

/** Opens a suite snapshot when a suite starts. */
final class SuiteStartedSubscriber implements StartedSubscriber
{
    /**
     * @param ProcessEnvironmentSnapshots $snapshots Stack shared by the five subscribers
     */
    public function __construct(private readonly ProcessEnvironmentSnapshots $snapshots)
    {
    }

    /**
     * Opens a suite snapshot. Which suite started is not read.
     *
     * @param Started $event Suite that started (unused)
     * @throws LogicException When the process environment cannot be read
     */
    public function notify(Started $event): void
    {
        $this->snapshots->openSuite();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Testing\ProcessEnvironment;

use Hilos\Core\Exception\LogicException;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/** Opens the running test's snapshot when preparation starts. */
final class TestPreparationStartedSubscriber implements PreparationStartedSubscriber
{
    /**
     * @param ProcessEnvironmentSnapshots $snapshots Stack shared by the five subscribers
     */
    public function __construct(private readonly ProcessEnvironmentSnapshots $snapshots)
    {
    }

    /**
     * Opens the test snapshot. Which test is preparing is not read.
     *
     * @param PreparationStarted $event Test whose preparation started (unused)
     * @throws LogicException When the process environment cannot be read
     */
    public function notify(PreparationStarted $event): void
    {
        $this->snapshots->openTest();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Testing\ProcessEnvironment;

use Hilos\Core\Exception\LogicException;
use PHPUnit\Event\TestSuite\Skipped;
use PHPUnit\Event\TestSuite\SkippedSubscriber;

/** Restores a suite snapshot when a suite is skipped and therefore never finishes. */
final class SuiteSkippedSubscriber implements SkippedSubscriber
{
    /**
     * @param ProcessEnvironmentSnapshots $snapshots Stack shared by the five subscribers
     */
    public function __construct(private readonly ProcessEnvironmentSnapshots $snapshots)
    {
    }

    /**
     * Closes the skipped suite. A skip emits no Finished event, so this close is the one the suite gets.
     *
     * @param Skipped $event Suite that was skipped (unused)
     * @throws LogicException When the process environment cannot be read
     */
    public function notify(Skipped $event): void
    {
        $this->snapshots->closeSuite();
    }
}

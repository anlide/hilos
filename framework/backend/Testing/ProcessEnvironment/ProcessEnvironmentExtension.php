<?php

declare(strict_types=1);

namespace Hilos\Testing\ProcessEnvironment;

use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\UnknownSubscriberTypeException;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/** Registers the subscribers that put the process environment back after each suite and test. */
final class ProcessEnvironmentExtension implements Extension
{
    /**
     * Builds one snapshot stack and hands it to the five subscribers.
     *
     * @param Configuration $configuration Loaded PHPUnit configuration (unused)
     * @param Facade $facade Door that registers subscribers
     * @param ParameterCollection $parameters Extension parameters (unused)
     * @throws EventFacadeIsSealedException When registration happens after the event facade is sealed
     * @throws UnknownSubscriberTypeException When a subscriber is not a known event type
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $snapshots = new ProcessEnvironmentSnapshots();
        $facade->registerSubscribers(
            new SuiteStartedSubscriber($snapshots),
            new SuiteFinishedSubscriber($snapshots),
            new SuiteSkippedSubscriber($snapshots),
            new TestPreparationStartedSubscriber($snapshots),
            new TestFinishedSubscriber($snapshots),
        );
    }
}

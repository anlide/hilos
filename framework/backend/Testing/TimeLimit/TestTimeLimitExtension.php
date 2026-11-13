<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use Hilos\Core\Exception\LogicException;
use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\UnknownSubscriberTypeException;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class TestTimeLimitExtension implements Extension
{
    /**
     * Registers one process alarm without configurable timing parameters.
     *
     * @param Configuration $configuration Loaded PHPUnit configuration (unused)
     * @param Facade $facade Subscriber registration
     * @param ParameterCollection $parameters Extension parameters (unused)
     * @throws LogicException When this process cannot support the required alarm
     * @throws EventFacadeIsSealedException When the event facade is already sealed
     * @throws UnknownSubscriberTypeException When an event subscriber is not recognized
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (
            !extension_loaded('pcntl')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_alarm')
        ) {
            throw new LogicException('The PHPUnit time limit requires pcntl signals and alarms');
        }

        $timeLimit = new TestTimeLimit();
        $facade->registerSubscribers(
            new ExecutionStartedSubscriber($timeLimit),
            new SuiteStartedSubscriber($timeLimit),
            new TestPreparationStartedSubscriber($timeLimit),
            new TestFinishedSubscriber($timeLimit),
            new TestPreparationFailedSubscriber($timeLimit),
            new TestSkippedSubscriber($timeLimit),
            new TestErroredSubscriber($timeLimit),
            new TestFailedSubscriber($timeLimit),
            new TestPassedSubscriber($timeLimit),
            new TestMarkedIncompleteSubscriber($timeLimit),
            new TestConsideredRiskySubscriber($timeLimit),
            new ExecutionFinishedSubscriber($timeLimit),
            new ExecutionAbortedSubscriber($timeLimit),
        );
    }
}
